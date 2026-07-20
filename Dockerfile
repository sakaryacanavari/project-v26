FROM node:20-alpine AS frontend-builder

WORKDIR /build
COPY package.json package-lock.json vite.config.js ./
COPY frontend ./frontend
RUN npm ci --ignore-scripts && npm run build

FROM php:8.4-apache

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
COPY .docker/php-production.ini /usr/local/etc/php/conf.d/project-production.ini
COPY .docker/docker-entrypoint.sh /usr/local/bin/v26-entrypoint
RUN chmod +x /usr/local/bin/v26-entrypoint

WORKDIR /var/www/html

COPY --from=frontend-builder /build/public/build /var/www/html/public/build

RUN mkdir -p /var/www/html/tmp/cache /var/www/html/tmp/logs /var/www/html/tmp/runtime \
    && chown -R www-data:www-data /var/www/html/tmp

ENTRYPOINT ["/usr/local/bin/v26-entrypoint"]
