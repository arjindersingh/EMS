FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y libpng-dev libonig-dev libxml2-dev libzip-dev default-mysql-client \
    && docker-php-ext-install pdo pdo_mysql \
    && a2enmod rewrite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
