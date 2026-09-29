#!/bin/sh
set -e

# Render has no separate free worker, so the queue (emails, WhatsApp) runs
# alongside the API in this container.

php artisan config:cache
php artisan route:cache
php artisan migrate --force

php artisan queue:work --tries=3 --timeout=60 &

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8000}"
