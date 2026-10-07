<?php

declare(strict_types=1);

namespace App;

use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;

/**
 * Per-request dispatch, invoked once per HTTP request from inside the
 * worker loop in public/worker.php. The instrument objects are created
 * once per worker process (see worker.php) and passed in, rather than
 * recreated on every request.
 *
 * Every request gets its own root span and its own OTel context scope,
 * which is explicitly detached in the finally block — required in worker
 * mode since the PHP process (and therefore the context storage) outlives
 * a single request.
 */
final class Kernel
{
    public static function handle(CounterInterface $requestCounter, HistogramInterface $aggregationDuration): void
    {
        $tracer = Telemetry::tracer();

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = rtrim(strtok($uri, '?') ?: '/', '/') ?: '/';

        $span = $tracer->spanBuilder("$method $path")
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttribute('http.method', $method)
            ->setAttribute('http.target', $uri)
            ->setAttribute('http.route', $path)
            ->startSpan();
        $scope = $span->activate();

        header('Content-Type: application/json');
        $requestCounter->add(1, ['http.route' => $path, 'http.method' => $method]);

        try {
            $response = match (true) {
                $path === '/health' => ['status' => 'ok'],
                $path === '/api/orders' && $method === 'GET' => Routes::listOrders(),
                $path === '/api/sales/by-category' && $method === 'GET' => Routes::salesByCategory($aggregationDuration),
                $path === '/api/sales/by-region' && $method === 'GET' => Routes::salesByRegion($aggregationDuration),
                $path === '/api/seed' && $method === 'POST' => Routes::seed(),
                default => null,
            };

            if ($response === null) {
                http_response_code(404);
                $span->setAttribute('http.status_code', 404);
                $span->setStatus(StatusCode::STATUS_ERROR, 'not found');
                echo json_encode(['error' => 'not found']);

                return;
            }

            $span->setAttribute('http.status_code', 200);
            echo json_encode($response, JSON_PRETTY_PRINT);
        } catch (\Throwable $e) {
            http_response_code(500);
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            echo json_encode(['error' => $e->getMessage()]);
        } finally {
            $span->end();
            $scope->detach();
            Telemetry::flush();
        }
    }
}
