FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
    curl \
    libpng-dev \
    oniguruma-dev \
    libxml2-dev \
    sqlite-dev \
    zip \
    unzip

RUN docker-php-ext-install pdo pdo_sqlite mbstring xml ctype fileinfo opcache

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

COPY . .
RUN composer run-script post-autoload-dump --no-interaction

RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 9000
ENTRYPOINT ["/entrypoint.sh"]
