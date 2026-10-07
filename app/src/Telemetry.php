<?php

declare(strict_types=1);

namespace App;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Trace\TracerInterface;

/**
 * Thin accessor around the globally configured OTel SDK.
 *
 * With OTEL_PHP_AUTOLOAD_ENABLED=true, open-telemetry/sdk's composer
 * "files" autoloader bootstraps a fully configured TracerProvider /
 * MeterProvider from OTEL_* env vars before this code ever runs, and wires
 * up any installed `*-auto-*` instrumentation packages. No manual exporter
 * setup is needed here — the app only asks Globals for a tracer/meter.
 */
final class Telemetry
{
    private const NAME = 'frankenphp-mongo-demo';

    public static function tracer(): TracerInterface
    {
        return Globals::tracerProvider()->getTracer(self::NAME);
    }

    public static function meter(): MeterInterface
    {
        return Globals::meterProvider()->getMeter(self::NAME);
    }

    /**
     * Short-lived PHP processes (one per request) don't live long enough
     * for the SDK's periodic exporters to fire on their own schedule, so
     * force a flush before the request ends.
     */
    public static function flush(): void
    {
        Globals::tracerProvider()->forceFlush();
        Globals::meterProvider()->forceFlush();
    }
}
