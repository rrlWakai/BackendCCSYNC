#!/bin/sh
set -eu

: "${PORT:=10000}"
export PORT

envsubst '${PORT}' < /etc/nginx/http.d/default.conf.template > /etc/nginx/http.d/default.conf

if [ -z "${APP_KEY:-}" ]; then
    echo "ERROR: APP_KEY must be set before the application can start." >&2
    exit 1
fi

php artisan config:cache

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

php artisan route:cache

exec supervisord -c /etc/supervisord.conf
