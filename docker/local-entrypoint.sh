#!/bin/sh
set -eu

until php artisan db:show >/dev/null 2>&1; do
    echo "Waiting for local PostgreSQL..."
    sleep 2
done

php artisan migrate --force
php artisan app:seed-if-empty

exec "$@"
