# FrankenPHP + MongoDB + OpenTelemetry

A minimal, runnable tutorial showing how to instrument a [FrankenPHP](https://frankenphp.dev)
app (running in **worker mode**) with [OpenTelemetry](https://opentelemetry.io/), including
traces and metrics for MongoDB aggregation queries, forwarded through an OpenTelemetry
Collector to Grafana Cloud.

## Architecture

```
┌──────────┐   HTTP    ┌───────────────────┐   OTLP/HTTP   ┌──────────────────┐   OTLP/HTTP   ┌───────────────┐
│  curl /  │ ────────▶ │  FrankenPHP app    │ ────────────▶ │  OTel Collector   │ ────────────▶ │ Grafana Cloud │
│ browser  │           │  (worker mode)     │               │  (contrib)        │               │  (Tempo/Mimir)│
└──────────┘           │  + ext-opentelemetry│              └──────────────────┘               └───────────────┘
                        │  + auto-mongodb     │
                        └─────────┬───────────┘
                                  │ MongoDB driver
                                  ▼
                           ┌─────────────┐
                           │  MongoDB    │
                           │  (orders)   │
                           └─────────────┘
```

Three containers, started with `docker compose up`:

- **app** — FrankenPHP (PHP 8.3) running the demo app as a persistent **worker**
- **mongo** — MongoDB 7, holding a `shop.orders` collection
- **otel-collector** — `otel/opentelemetry-collector-contrib`, receives OTLP from the app
  and forwards it to Grafana Cloud (and prints it locally via a `debug` exporter so you can
  see telemetry flowing even before Grafana Cloud credentials are configured)

## How the instrumentation works

This uses PHP's closest equivalent to a "zero-code" auto-instrumentation agent:

1. **`ext-opentelemetry`** (a PECL extension, installed in the `Dockerfile`) provides a
   `hook()` mechanism that can intercept method calls on any class without touching its
   source — this is what makes auto-instrumentation possible in PHP.
2. **`open-telemetry/opentelemetry-auto-mongodb`** (a Composer package) uses that hook to
   wrap every call the MongoDB driver makes (`aggregate`, `find`, `insertMany`, ...) in its
   own span — **no application code required**.
3. Setting `OTEL_PHP_AUTOLOAD_ENABLED=true` tells `open-telemetry/sdk` to bootstrap a fully
   configured `TracerProvider`/`MeterProvider` straight from `OTEL_*` environment variables
   the moment `vendor/autoload.php` is required, and to register every installed `*-auto-*`
   instrumentation package's hooks. No manual exporter/provider wiring in app code.
4. FrankenPHP has no PSR-15 auto-instrumentation package yet, so [`app/src/Kernel.php`](app/src/Kernel.php)
   manually starts one root HTTP span per request (`$tracer->spanBuilder(...)`) and ends it
   in a `finally` block — about 10 lines, see "Why worker mode changes this" below.
5. [`app/src/Routes.php`](app/src/Routes.php) adds a couple of extra child spans with
   domain-specific attributes (`db.mongodb.pipeline_stages`, `mongodb.result_count`, ...)
   around each aggregation, plus a `mongodb.aggregation.duration` histogram metric — this is
   the "visibility into the aggregation" piece.

### Why worker mode changes this

The app runs as a FrankenPHP **worker** ([`app/public/index.php`](app/public/index.php) is
the worker script — see [`app/Caddyfile`](app/Caddyfile)): it boots once and then handles
every request in a loop via `frankenphp_handle_request()`, instead of a fresh PHP process
per request. That has two consequences, both handled already:

- **Good**: the Mongo client connection, the OTel hook registration, and the metric
  instruments are all created exactly once (in the loop's setup, before `for`), not
  re-created on every request — see the top of `app/public/index.php`.
- **Careful**: because the process (and OTel's context storage) persists across requests,
  every request's span must be explicitly ended and its context scope explicitly
  `detach()`-ed in a `finally` block (in `Kernel::handle()`), otherwise span context would
  leak from one request into the next. The SDK's periodic metric/trace exporters also don't
  get a chance to run on their own schedule between requests, so `Telemetry::flush()`
  force-flushes both providers at the end of every request instead.

## Prerequisites

- Docker + Docker Compose
- A Grafana Cloud account (for actually sending data somewhere — the app and collector work
  fine locally without it, see below)

## Running it

```bash
docker compose up -d --build
```

Then:

```bash
curl http://localhost:8080/health
curl http://localhost:8080/api/orders
curl http://localhost:8080/api/sales/by-category
curl http://localhost:8080/api/sales/by-region
```

The `orders` collection seeds itself with 600 synthetic orders on first use (see
`Routes::ensureSeeded()`). To force a fresh batch: `curl -X POST http://localhost:8080/api/seed`.

Watch spans and metrics arrive at the collector in real time:

```bash
docker compose logs -f otel-collector
```

You'll see `ResourceSpans`/`ResourceMetrics` entries even with no Grafana Cloud credentials
configured — that's the `debug` exporter. Once you add real credentials (next section), the
same data also gets forwarded to Grafana Cloud via the `otlphttp/grafanacloud` exporter.

## Sending data to Grafana Cloud

Credentials go in `.env` (copy `.env.example`; `.env` is gitignored) — fill in the two
blank values:

```bash
GRAFANA_CLOUD_OTLP_ENDPOINT=https://otlp-gateway-prod-us-east-0.grafana.net/otlp   # already set
GRAFANA_CLOUD_INSTANCE_ID=                                                         # fill in
GRAFANA_CLOUD_API_TOKEN=                                                           # fill in
```

To get the instance ID and token for your Grafana Cloud stack:

1. Open the [Grafana Cloud Portal](https://grafana.com/auth/sign-in) and select your stack.
2. Go to **Connections → Add new connection → OpenTelemetry**.
3. That page shows the exact OTLP endpoint and **Instance ID** for your stack (should match
   the endpoint already in `.env` — the region is `prod-us-east-0`), and has a
   **"Generate now"** button that creates a scoped Access Policy token for you (minimally
   needs `metrics:write` and `traces:write`).
4. Paste the Instance ID and token into `.env`.

Then restart just the collector to pick up the new credentials:

```bash
docker compose up -d --build otel-collector
```

Hit a few endpoints again, then in Grafana Cloud:

- **Explore → Tempo** — search by `service.name = frankenphp-mongo-demo`, or by span name
  e.g. `mongodb.aggregate orders by category`
- **Explore → Mimir/Prometheus** — query `mongodb_aggregation_duration_milliseconds_bucket`
  or `http_server_requests_total`

> I generated this scaffold from a sandboxed environment that's deliberately blocked from
> extracting live credentials out of local config files (even your own `gcx` config) and
> wiring them in automatically — that's why this is a manual one-time paste instead of
> something scripted.

## Project layout

```
app/
  Dockerfile            # dunglas/frankenphp + ext-mongodb + ext-opentelemetry
  Caddyfile             # configures the worker (app/public/index.php)
  composer.json
  public/
    index.php           # the worker script: one-time boot + per-request loop
  src/
    Telemetry.php        # thin accessor for the auto-configured Tracer/MeterProvider
    Database.php          # Mongo client, created once per worker
    Kernel.php             # per-request dispatch + root HTTP span
    Routes.php              # route handlers, aggregation spans/metrics, seeding
docker-compose.yml
otel-collector-config.yaml
.env.example / .env
```

## Troubleshooting

- **401 from `otlphttp/grafanacloud` in collector logs** — `GRAFANA_CLOUD_INSTANCE_ID` or
  `GRAFANA_CLOUD_API_TOKEN` is wrong/empty. The `debug` exporter still works regardless.
- **No MongoDB spans** — confirm `ext-opentelemetry` loaded (`docker compose exec app php -m | grep -i otel`)
  and that `OTEL_PHP_AUTOLOAD_ENABLED=true` is set (it is, in `docker-compose.yml`).
- **Changed PHP code isn't taking effect** — worker mode keeps the app booted in memory;
  restart the app container (`docker compose restart app`) after editing `app/src/*.php`.
  For live-reload during development, add a `watch` directive to the worker block in
  `app/Caddyfile` (see [FrankenPHP docs](https://frankenphp.dev/docs/worker/#watching-for-file-changes)).
