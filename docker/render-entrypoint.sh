#!/bin/sh
set -eu

port="${PORT:-10000}"
case "$port" in
    *[!0-9]*|'')
        echo "PORT must be a numeric value." >&2
        exit 1
        ;;
esac

sed -i "s/^Listen 80$/Listen ${port}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \\*:80>/<VirtualHost *:${port}>/" /etc/apache2/sites-enabled/000-default.conf

export APP_URL="${APP_URL:-${RENDER_EXTERNAL_URL:-http://localhost}}"

php artisan storage:link --force
php artisan config:cache
php artisan migrate --force

exec apache2-foreground
