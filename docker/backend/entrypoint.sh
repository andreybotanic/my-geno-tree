#!/bin/sh
set -eu

media_directory=/srv/backend/var/storage/media

mkdir -p "$media_directory"
chown -R www-data:www-data "$media_directory"

exec docker-php-entrypoint "$@"
