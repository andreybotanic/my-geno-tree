#!/bin/sh
set -eu

storage_directory=/srv/backend/var/storage
media_directory="$storage_directory/media"
mkdir -p "$media_directory/documents" "$storage_directory/document-locks"
chown -R www-data:www-data "$media_directory" "$storage_directory/document-locks"

# SDK file sessions are shared by PHP-FPM workers, independently of CLI cache warmup.
for mcp_environment in dev prod; do
    mcp_directory="/run/chat/mcp_$mcp_environment"
    mkdir -p "$mcp_directory"
    chown www-data:www-data "$mcp_directory"
    chmod 770 "$mcp_directory"
done

exec docker-php-entrypoint "$@"
