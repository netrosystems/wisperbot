# syntax=docker/dockerfile:1.7

FROM composer:2 AS composer

FROM node:22-bookworm-slim AS frontend
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY public ./public
COPY vite.config.js postcss.config.js tailwind.config.js ./
RUN npm run build

FROM php:8.4-fpm-bookworm AS app

ARG APP_UID=1000
ARG APP_GID=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        ffmpeg \
        git \
        gosu \
        imagemagick \
        libc-client2007e-dev \
        libfreetype6-dev \
        libheif-examples \
        libicu-dev \
        libjpeg62-turbo-dev \
        libkrb5-dev \
        libmagickwand-dev \
        libpng-dev \
        libssl-dev \
        libwebp-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" bcmath exif gd intl opcache pcntl pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

# Keep PECL extensions in separate layers so a transient download failure does
# not force every PHP extension to be rebuilt. Versions are pinned for repeatable
# VPS deployments; IMAP is a PECL extension starting with PHP 8.4.
RUN pecl install imap-1.0.3 \
    && docker-php-ext-enable imap \
    && rm -rf /tmp/pear
RUN pecl install imagick-3.8.1 \
    && docker-php-ext-enable imagick \
    && rm -rf /tmp/pear
RUN pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/pear

RUN groupmod -o -g "${APP_GID}" www-data \
    && usermod -o -u "${APP_UID}" -g www-data www-data

WORKDIR /var/www/html

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
# Composer's optimized classmap scans this application-owned directory during
# install, so it must exist before the dependency layer is generated.
COPY app/Modules/Integrations/database/seeders ./app/Modules/Integrations/database/seeders
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts

COPY --chown=www-data:www-data . .
COPY --from=frontend --chown=www-data:www-data /build/public/build ./public/build
COPY docker/production/php.ini /usr/local/etc/php/conf.d/99-wisperbot.ini
COPY docker/production/entrypoint.sh /usr/local/bin/wisperbot-entrypoint

RUN chmod +x /usr/local/bin/wisperbot-entrypoint \
    && mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && php artisan package:discover --ansi \
    && ln -sfn /var/www/html/storage/app/public /var/www/html/public/storage

ENTRYPOINT ["wisperbot-entrypoint"]
CMD ["php-fpm", "-F"]

FROM nginx:1.27-alpine AS web
COPY docker/production/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
WORKDIR /var/www/html

FROM alpine:3.21 AS backup
RUN apk add --no-cache mariadb-client tzdata
COPY docker/production/backup-container.sh /usr/local/bin/wisperbot-backup
RUN chmod +x /usr/local/bin/wisperbot-backup
ENTRYPOINT ["wisperbot-backup"]

# Optional verification target. Production images keep development packages out,
# while this target makes the complete Laravel suite reproducible in Docker.
FROM app AS test
RUN touch .env \
    && composer install --no-interaction --no-progress --prefer-dist --optimize-autoloader
