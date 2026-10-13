FROM php:8.3-fpm-alpine

RUN apk add --no-cache git unzip \
    && docker-php-ext-install pdo_mysql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Фото к сообщениям: том uploads монтируется сюда, писать в него должен PHP-FPM (www-data)
RUN mkdir -p /var/www/uploads && chown www-data:www-data /var/www/uploads

WORKDIR /var/www/html
