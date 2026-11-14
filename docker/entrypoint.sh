#!/bin/sh
set -e

# Only the API container applies migrations; workers wait for it to be healthy.
if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
    php artisan migrate --force --no-interaction
fi

exec "$@"
