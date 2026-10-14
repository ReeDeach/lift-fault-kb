FROM php:8.3-fpm-alpine

# zip — чтение отчётов 1С в формате XLSX (это zip-архив с XML)
RUN apk add --no-cache git unzip libzip-dev \
    && docker-php-ext-install pdo_mysql zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Фото к сообщениям: том uploads монтируется сюда, писать в него должен PHP-FPM (www-data)
RUN mkdir -p /var/www/uploads && chown www-data:www-data /var/www/uploads

WORKDIR /var/www/html
