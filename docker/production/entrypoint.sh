#!/bin/sh
set -eu

mkdir -p \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache
ln -sfn /var/www/html/storage/app/public /var/www/html/public/storage

if [ "${1:-}" = "php-fpm" ]; then
    exec "$@"
fi

exec gosu www-data "$@"
