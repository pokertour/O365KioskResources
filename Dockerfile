# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------------------------
# O365 Kiosk Resources — production image (FrankenPHP, PHP 8.5)
#
#   docker build --build-arg APP_VERSION=1.2.3 -t o365-kiosk .
#
# One image, three roles (first argument): app (web, default) · worker (queue) · scheduler
# ---------------------------------------------------------------------------------------------

ARG FRANKENPHP_IMAGE=dunglas/frankenphp:1-php8.5
ARG NODE_IMAGE=node:22-alpine

# --- Base: PHP extensions shared by every stage ------------------------------------------------
FROM ${FRANKENPHP_IMAGE} AS base

RUN install-php-extensions \
        intl \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        redis \
        zip

WORKDIR /app

# --- Composer dependencies (production only) --------------------------------------------------
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --no-dev --optimize \
    && php artisan package:discover --ansi

# --- Front-end assets (Vite / Tailwind) ---------------------------------------------------------
FROM ${NODE_IMAGE} AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY . .
# Tailwind imports Flux's stylesheet and scans its Blade stubs.
COPY --from=vendor /app/vendor/livewire/flux /app/vendor/livewire/flux
COPY --from=vendor /app/vendor/laravel/framework/src/Illuminate/Pagination/resources/views /app/vendor/laravel/framework/src/Illuminate/Pagination/resources/views
RUN npm run build

# --- Runtime -------------------------------------------------------------------------------------
FROM base AS app

ARG APP_VERSION=local-dev

ENV APP_ENV=production \
    APP_DEBUG=false \
    APP_VERSION=${APP_VERSION} \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/app/storage/database.sqlite \
    SERVER_NAME=:8080 \
    RUN_MIGRATIONS=false

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint

COPY --from=vendor --chown=www-data:www-data /app /app
COPY --from=assets --chown=www-data:www-data /app/public/build /app/public/build

# Runs as www-data on an unprivileged port; Caddy needs its config/data dirs writable.
RUN chmod +x /usr/local/bin/docker-entrypoint \
    && rm -f public/hot \
    && mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache /config/caddy /data/caddy

USER www-data

LABEL org.opencontainers.image.title="O365 Kiosk Resources" \
      org.opencontainers.image.description="Microsoft 365 meeting room screens" \
      org.opencontainers.image.version="${APP_VERSION}"

EXPOSE 8080
VOLUME ["/app/storage"]

# Web role only: disable it for the worker and scheduler containers (see compose.example.yaml).
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/up > /dev/null || exit 1

ENTRYPOINT ["docker-entrypoint"]
CMD ["app"]
