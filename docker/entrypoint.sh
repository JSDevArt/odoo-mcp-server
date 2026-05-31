#!/bin/sh
set -e

if [ -z "$APP_KEY" ]; then
    php artisan key:generate --force --no-interaction
fi

php artisan config:cache
php artisan route:cache
php artisan migrate --force --no-interaction

exec php-fpm
