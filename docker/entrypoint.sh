#!/bin/sh
# Container entrypoint. Role: app (web server, default) | worker (queue) | scheduler | any command.
set -e

cd /app
role="${1:-app}"

case "$role" in
    app|worker|scheduler)
        if [ -z "$APP_KEY" ]; then
            echo "APP_KEY is not set. Generate one with: docker run --rm <image> php artisan key:generate --show" >&2
            exit 1
        fi
        ;;
esac

# The storage volume may be empty on first start.
mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions \
         storage/framework/views storage/logs bootstrap/cache

# SQLite: create the database file on first start.
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    db="${DB_DATABASE:-/app/storage/database.sqlite}"
    [ -f "$db" ] || touch "$db"
fi

case "$role" in
    app|worker|scheduler)
        php artisan optimize --no-interaction
        ;;
esac

case "$role" in
    app)
        if [ "$RUN_MIGRATIONS" = "true" ]; then
            php artisan migrate --force --no-interaction
        fi
        exec frankenphp run --config /etc/frankenphp/Caddyfile
        ;;
    worker)
        exec php artisan queue:work --tries=3 --max-time=3600 --no-interaction
        ;;
    scheduler)
        exec php artisan schedule:work --no-interaction
        ;;
    *)
        exec "$@"
        ;;
esac
