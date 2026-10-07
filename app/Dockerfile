FROM dunglas/frankenphp:1-php8.3

# ext-mongodb: talks to MongoDB
# ext-opentelemetry: provides the hook() mechanism the OTel auto-instrumentation
#                     packages (e.g. opentelemetry-auto-mongodb) use to wrap
#                     method calls without touching application code.
RUN apt-get update && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions \
    mongodb \
    opentelemetry \
    pcntl \
    sockets

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader

COPY . .

COPY Caddyfile /etc/frankenphp/Caddyfile

ENV SERVER_NAME=:80
EXPOSE 80
