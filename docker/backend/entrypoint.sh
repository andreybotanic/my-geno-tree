#!/bin/sh
set -eu

media_directory=/srv/backend/var/storage/media

mkdir -p "$media_directory"
chown -R www-data:www-data "$media_directory"

if [ "${TREE_MERGE_AI:-ollama}" = opencode ]; then
    php /usr/local/lib/prepare-opencode-auth.php
fi

exec docker-php-entrypoint "$@"
