# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# 1) PHP dependencies (Composer)
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-scripts \
        --prefer-dist \
        --optimize-autoloader

COPY . .
RUN composer dump-autoload --optimize --no-scripts

# ---------------------------------------------------------------------------
# 2) Front-end assets (Vite)
# ---------------------------------------------------------------------------
FROM node:22-bookworm-slim AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build

# ---------------------------------------------------------------------------
# 3) Runtime (PHP CLI serving the app)
# ---------------------------------------------------------------------------
FROM php:8.2-cli-bookworm AS app

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libonig-dev libxml2-dev \
    && docker-php-ext-install -j"$(nproc)" bcmath mbstring zip dom \
    && rm -rf /var/lib/apt/lists/*

COPY --from=vendor /app /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build

RUN mkdir -p \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        bootstrap/cache \
    && chmod -R 777 storage bootstrap/cache

ENV PORT=10000
EXPOSE 10000

CMD php artisan package:discover --ansi \
    && php artisan config:clear \
    && php artisan serve --host=0.0.0.0 --port=${PORT}
