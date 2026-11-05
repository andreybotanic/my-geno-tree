FROM node:24.18.0-bookworm-slim AS acp-client
WORKDIR /opt/acp-client
COPY docker/backend/acpx/package*.json ./
RUN npm ci --omit=dev --no-audit --no-fund

FROM php:8.5.8-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS \
        git \
        ffmpeg \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libpq-dev \
        libwebp-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd exif intl pdo_pgsql zip \
    && pecl install pcov-1.0.12 \
    && docker-php-ext-enable pcov \
    && rm -rf /var/lib/apt/lists/*

RUN apt-get update && apt-get install -y --no-install-recommends socat && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.10.2 /usr/bin/composer /usr/local/bin/composer
COPY --from=acp-client /usr/local/bin/node /usr/local/bin/node
COPY --from=acp-client /opt/acp-client /opt/acp-client
COPY docker/backend/entrypoint.sh /usr/local/bin/mygenotree-entrypoint

RUN chmod +x /usr/local/bin/mygenotree-entrypoint

ENTRYPOINT ["mygenotree-entrypoint"]
CMD ["php-fpm"]

WORKDIR /srv/backend

COPY docker/backend/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY docker/backend/import-timeout.conf /usr/local/etc/php-fpm.d/zz-import-timeout.conf
COPY docker/backend/mcp-pool.conf /usr/local/etc/php-fpm.d/zz-mcp-pool.conf
