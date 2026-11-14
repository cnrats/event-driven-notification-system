# syntax=docker/dockerfile:1

# One image for every role: API (FrankenPHP), Horizon workers, sweeper,
# Reverb and the mock provider. The role is chosen by the container command.
FROM dunglas/frankenphp:1-php8.4 AS base

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_mysql redis pcntl intl zip opcache

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    SERVER_NAME=:8000

WORKDIR /app

# ---------------------------------------------------------------------------
# Image with the application baked in (what `docker compose up` runs).
FROM base AS app

COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint

# The base image's health check probes the Caddy admin API, which only the
# API role runs. Each service defines its own check in docker-compose.yml.
HEALTHCHECK NONE

ENTRYPOINT ["app-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
