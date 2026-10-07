#!/bin/sh
set -e

# The demo does not store encrypted data, so a throwaway key is fine when none is given.
if [ -z "${APP_KEY:-}" ]; then
    APP_KEY="$(php artisan key:generate --show)"
    export APP_KEY
fi

# Only the web container migrates; worker and scheduler wait for it to be healthy.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

exec "$@"
