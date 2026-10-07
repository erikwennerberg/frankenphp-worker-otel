<?php

declare(strict_types=1);

use App\Database;
use App\Kernel;
use App\Telemetry;

ignore_user_abort(true);

require __DIR__ . '/../vendor/autoload.php';

// Everything below runs ONCE per worker process, not per request:
// - OTel SDK bootstrap + auto-instrumentation hooks (triggered by the
//   vendor/autoload.php require above, via OTEL_PHP_AUTOLOAD_ENABLED)
// - the Mongo client connection
// - the metric instruments
// All of it is then reused by every request this worker serves.
Database::client();

$meter = Telemetry::meter();
$requestCounter = $meter->createCounter(
    'http.server.requests',
    'requests',
    'Total HTTP requests handled'
);
$aggregationDuration = $meter->createHistogram(
    'mongodb.aggregation.duration',
    'ms',
    'Duration of MongoDB aggregation pipelines'
);

$handler = static function () use ($requestCounter, $aggregationDuration): void {
    Kernel::handle($requestCounter, $aggregationDuration);
};

$maxRequests = (int) ($_SERVER['MAX_REQUESTS'] ?? 0);

for ($count = 0; !$maxRequests || $count < $maxRequests; $count++) {
    $keepRunning = \frankenphp_handle_request($handler);

    gc_collect_cycles();

    if (!$keepRunning) {
        break;
    }
}
