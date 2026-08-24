# ==============================================================================
# RemitIQ Household Service
# Laravel PHP Application
# ==============================================================================


# ------------------------------------------------------------------------------
# Stage 1: Production Composer Dependencies
# ------------------------------------------------------------------------------

FROM composer:2 AS composer-production

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --no-scripts \
    --no-autoloader \
    --ignore-platform-reqs

COPY . .

# Provide a temporary env file for package discovery scripts during build
RUN cp .env.example .env || touch .env

RUN composer dump-autoload \
    --optimize \
    --no-dev

RUN php artisan package:discover --ansi


# ------------------------------------------------------------------------------
# Stage 2: Development Composer Dependencies
# ------------------------------------------------------------------------------

FROM composer:2 AS composer-development

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --no-scripts \
    --no-autoloader \
    --ignore-platform-reqs

COPY . .

RUN cp .env.example .env || touch .env

RUN composer dump-autoload --optimize

RUN php artisan package:discover --ansi


# ------------------------------------------------------------------------------
# Stage 3: PHP Runtime
# ------------------------------------------------------------------------------

FROM php:8.3-fpm-alpine AS php-base

WORKDIR /var/www/html

RUN apk add --no-cache \
        postgresql-dev \
        icu-dev \
        oniguruma-dev \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        unzip \
    && docker-php-ext-configure gd \
        --with-jpeg \
        --with-freetype \
    && docker-php-ext-install \
        pdo \
        pdo_pgsql \
        intl \
        mbstring \
        bcmath \
        opcache \
        zip \
        exif \
        gd

COPY docker/php/php.ini \
    /usr/local/etc/php/conf.d/custom.ini


# ------------------------------------------------------------------------------
# Stage 4: Development
# ------------------------------------------------------------------------------

FROM php-base AS development

ARG WWWUSER=1000
ARG WWWGROUP=1000

RUN apk add --no-cache \
        git \
        curl

RUN curl -sS https://getcomposer.org/installer | php \
        -- --install-dir=/usr/local/bin \
        --filename=composer

RUN deluser www-data 2>/dev/null || true && \
    delgroup www-data 2>/dev/null || true && \
    addgroup -g ${WWWGROUP} www-data && \
    adduser -D \
        -u ${WWWUSER} \
        -G www-data \
        www-data

RUN git config --global --add safe.directory /var/www/html

COPY docker/php/opcache-dev.ini \
    /usr/local/etc/php/conf.d/opcache.ini

COPY . .

COPY --from=composer-development \
    /app/vendor \
    ./vendor

RUN mkdir -p storage bootstrap/cache && \
    chown -R www-data:www-data \
        storage \
        bootstrap/cache

EXPOSE 9000

CMD ["php-fpm"]


# ------------------------------------------------------------------------------
# Stage 5: Production
# ------------------------------------------------------------------------------

FROM php-base AS production

COPY docker/php/opcache-prod.ini \
    /usr/local/etc/php/conf.d/opcache.ini

COPY . .

COPY --from=composer-production \
    /app/vendor \
    ./vendor

RUN mkdir -p storage bootstrap/cache && \
    chown -R www-data:www-data \
        storage \
        bootstrap/cache

USER www-data

EXPOSE 9000

CMD ["php-fpm"]
