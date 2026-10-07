<?php

declare(strict_types=1);

namespace App;

use MongoDB\BSON\UTCDateTime;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;

/**
 * Route handlers. Each one that talks to MongoDB opens its own child span
 * so the aggregation pipeline is visible as its own unit of work in the
 * trace, separate from the HTTP root span created in Kernel::handle().
 *
 * The MongoDB driver calls themselves (Manager::executeCommand etc., used
 * under the hood by aggregate()/find()/insertMany()) are additionally
 * auto-instrumented by open-telemetry/opentelemetry-auto-mongodb, so each
 * of these spans gets a "mongodb" child span for free with no code here.
 */
final class Routes
{
    private const CATEGORIES = ['electronics', 'books', 'home', 'toys', 'clothing', 'sports'];
    private const REGIONS = ['us-east', 'us-west', 'eu-west', 'eu-central', 'apac'];

    public static function listOrders(): array
    {
        self::ensureSeeded();

        $cursor = Database::orders()->find(
            [],
            ['sort' => ['createdAt' => -1], 'limit' => 20]
        );

        return ['orders' => array_map(self::toArray(...), $cursor->toArray())];
    }

    public static function salesByCategory(HistogramInterface $aggregationDuration): array
    {
        self::ensureSeeded();

        $tracer = Telemetry::tracer();
        $span = $tracer->spanBuilder('mongodb.aggregate orders by category')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('db.system', 'mongodb')
            ->setAttribute('db.name', $_ENV['MONGODB_DB'] ?? 'shop')
            ->setAttribute('db.mongodb.collection', 'orders')
            ->setAttribute('db.operation', 'aggregate')
            ->startSpan();
        $scope = $span->activate();

        $start = microtime(true);
        try {
            $pipeline = [
                ['$group' => [
                    '_id' => '$category',
                    'totalAmount' => ['$sum' => '$amount'],
                    'orders' => ['$sum' => 1],
                    'avgOrderValue' => ['$avg' => '$amount'],
                ]],
                ['$sort' => ['totalAmount' => -1]],
            ];
            $span->setAttribute('db.mongodb.pipeline_stages', count($pipeline));

            $results = Database::orders()->aggregate($pipeline)->toArray();

            $span->setAttribute('mongodb.result_count', count($results));

            return [
                'by_category' => array_map(
                    static fn ($row) => [
                        'category' => $row['_id'],
                        'totalAmount' => round((float) $row['totalAmount'], 2),
                        'orders' => $row['orders'],
                        'avgOrderValue' => round((float) $row['avgOrderValue'], 2),
                    ],
                    $results
                ),
            ];
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            throw $e;
        } finally {
            $durationMs = (microtime(true) - $start) * 1000;
            $aggregationDuration->record($durationMs, ['pipeline' => 'by_category']);
            $span->end();
            $scope->detach();
        }
    }

    public static function salesByRegion(HistogramInterface $aggregationDuration): array
    {
        self::ensureSeeded();

        $tracer = Telemetry::tracer();
        $span = $tracer->spanBuilder('mongodb.aggregate orders by region/day')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('db.system', 'mongodb')
            ->setAttribute('db.name', $_ENV['MONGODB_DB'] ?? 'shop')
            ->setAttribute('db.mongodb.collection', 'orders')
            ->setAttribute('db.operation', 'aggregate')
            ->startSpan();
        $scope = $span->activate();

        $start = microtime(true);
        try {
            $since = new UTCDateTime((time() - 30 * 86400) * 1000);
            $pipeline = [
                ['$match' => ['createdAt' => ['$gte' => $since]]],
                ['$group' => [
                    '_id' => [
                        'region' => '$region',
                        'day' => ['$dateTrunc' => ['date' => '$createdAt', 'unit' => 'day']],
                    ],
                    'totalAmount' => ['$sum' => '$amount'],
                    'orders' => ['$sum' => 1],
                ]],
                ['$sort' => ['_id.day' => 1, 'totalAmount' => -1]],
                ['$limit' => 100],
            ];
            $span->setAttribute('db.mongodb.pipeline_stages', count($pipeline));

            $results = Database::orders()->aggregate($pipeline)->toArray();

            $span->setAttribute('mongodb.result_count', count($results));

            return [
                'by_region_day' => array_map(
                    static fn ($row) => [
                        'region' => $row['_id']['region'],
                        'day' => $row['_id']['day']->toDateTime()->format('Y-m-d'),
                        'totalAmount' => round((float) $row['totalAmount'], 2),
                        'orders' => $row['orders'],
                    ],
                    $results
                ),
            ];
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            throw $e;
        } finally {
            $durationMs = (microtime(true) - $start) * 1000;
            $aggregationDuration->record($durationMs, ['pipeline' => 'by_region']);
            $span->end();
            $scope->detach();
        }
    }

    public static function seed(): array
    {
        $inserted = self::seedOrders(force: true);

        return ['seeded' => $inserted];
    }

    private static function ensureSeeded(): void
    {
        if (Database::orders()->countDocuments() === 0) {
            self::seedOrders(force: false);
        }
    }

    private static function seedOrders(bool $force): int
    {
        $tracer = Telemetry::tracer();
        $span = $tracer->spanBuilder('seed.orders')
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->startSpan();
        $scope = $span->activate();

        try {
            $collection = Database::orders();

            if ($force) {
                $collection->deleteMany([]);
            }

            $count = 600;
            $now = time();
            $docs = [];
            for ($i = 0; $i < $count; $i++) {
                $category = self::CATEGORIES[array_rand(self::CATEGORIES)];
                $region = self::REGIONS[array_rand(self::REGIONS)];
                $price = round(mt_rand(500, 25000) / 100, 2);
                $quantity = mt_rand(1, 5);
                $createdAt = $now - mt_rand(0, 30 * 86400);

                $docs[] = [
                    'customer' => 'customer-' . mt_rand(1, 200),
                    'category' => $category,
                    'region' => $region,
                    'price' => $price,
                    'quantity' => $quantity,
                    'amount' => round($price * $quantity, 2),
                    'createdAt' => new UTCDateTime($createdAt * 1000),
                ];
            }

            $collection->insertMany($docs);
            $span->setAttribute('seed.orders_inserted', $count);

            return $count;
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            throw $e;
        } finally {
            $span->end();
            $scope->detach();
        }
    }

    private static function toArray(object $doc): array
    {
        return [
            'id' => (string) $doc->_id,
            'customer' => $doc->customer,
            'category' => $doc->category,
            'region' => $doc->region,
            'price' => $doc->price,
            'quantity' => $doc->quantity,
            'amount' => $doc->amount,
            'createdAt' => $doc->createdAt->toDateTime()->format('Y-m-d H:i:s'),
        ];
    }
}
