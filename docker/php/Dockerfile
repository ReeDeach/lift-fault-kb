FROM php:8.3-fpm-alpine

RUN apk add --no-cache git unzip \
    && docker-php-ext-install pdo_mysql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
