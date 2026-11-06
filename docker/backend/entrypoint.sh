#!/bin/sh
set -eu

media_directory=/srv/backend/var/storage/media

mkdir -p "$media_directory"
chown -R www-data:www-data "$media_directory"

# SDK file sessions are shared by PHP-FPM workers, independently of CLI cache warmup.
for mcp_environment in dev prod; do
    mcp_directory="/run/chat/mcp_$mcp_environment"
    mkdir -p "$mcp_directory"
    chown www-data:www-data "$mcp_directory"
    chmod 770 "$mcp_directory"
done

exec docker-php-entrypoint "$@"
