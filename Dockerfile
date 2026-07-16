FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libonig-dev \
        libzip-dev \
        zip \
    && docker-php-ext-install intl mbstring mysqli opcache pdo_mysql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-enable opcache \
    && a2enmod rewrite headers deflate expires \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY .docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY .docker/php.ini /usr/local/etc/php/conf.d/project.ini

WORKDIR /var/www/html

RUN mkdir -p /var/www/html/tmp/cache /var/www/html/tmp/logs /var/www/html/tmp/runtime \
    && chown -R www-data:www-data /var/www/html/tmp
