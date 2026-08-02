# PHP 8.4 FPM — the canonical backend runtime.
# Local PHP installations are not used; all backend commands run in this image.
FROM php:8.4-fpm-alpine AS base

RUN apk add --no-cache \
        git \
        icu-dev \
        libpq-dev \
        libzip-dev \
        linux-headers \
        unzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        opcache \
        pdo_pgsql \
        zip \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1

WORKDIR /app

# ---------------------------------------------------------------------------
# Development target: Xdebug for coverage, permissive opcache for fast reloads.
# ---------------------------------------------------------------------------
FROM base AS dev

RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && pecl install xdebug-3.4.1 \
    && docker-php-ext-enable xdebug \
    && apk del .build-deps

COPY docker/php/conf.d/ /usr/local/etc/php/conf.d/

# ---------------------------------------------------------------------------
# Production target: dependencies baked in, opcache tuned, no dev tooling.
# ---------------------------------------------------------------------------
FROM base AS prod

COPY docker/php/conf.d/ /usr/local/etc/php/conf.d/
COPY docker/php/conf.d-prod/ /usr/local/etc/php/conf.d/

COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY backend/ ./
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && mkdir -p var/cache var/log \
    && chown -R www-data:www-data var
