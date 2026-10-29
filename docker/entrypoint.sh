#!/bin/sh
set -e

cd /var/www/html

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/public storage/app/private
chown -R www-data:www-data storage bootstrap/cache

as_www() { su -s /bin/sh www-data -c "$*"; }

if [ "$#" -gt 0 ]; then
    exec su -s /bin/sh www-data -c "exec $*"
fi

as_www "php artisan storage:link --force"
as_www "php artisan optimize"

exec supervisord -c /etc/supervisord.conf
