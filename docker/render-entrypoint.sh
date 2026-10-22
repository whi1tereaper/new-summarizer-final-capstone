#!/usr/bin/env bash
set -Eeuo pipefail

port="${PORT:-10000}"
if [[ ! "$port" =~ ^[0-9]+$ ]] || (( port < 1 || port > 65535 )); then
    echo "Invalid PORT: $port" >&2
    exit 1
fi

sed -ri "s/^Listen [0-9]+/Listen ${port}/" /etc/apache2/ports.conf
sed -ri "s#<VirtualHost \*:[0-9]+>#<VirtualHost *:${port}>#" /etc/apache2/sites-available/000-default.conf

mkdir -p \
    /var/www/html/storage/uploads \
    /var/www/html/storage/audio \
    /var/www/html/storage/logs \
    /var/www/html/storage/tmp \
    /var/www/html/python-engine/output/tts \
    /var/www/html/python-engine/.tmp
chown -R www-data:www-data \
    /var/www/html/storage \
    /var/www/html/python-engine/output \
    /var/www/html/python-engine/.tmp

exec "$@"
