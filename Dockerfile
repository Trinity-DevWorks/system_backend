# PHP-FPM + nginx for concurrent HTTP (artisan serve is single-threaded and
# makes navigation feel slow when the UI fires many API calls in parallel).
FROM php:8.3-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip curl nginx supervisor \
        libzip-dev libpng-dev libicu-dev libonig-dev libpq-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-install -j$(nproc) \
        bcmath gd intl mbstring pdo_pgsql zip opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/* \
    && rm -f /etc/nginx/sites-enabled/default

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader

COPY . .
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/laravel.conf
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/php/zz-fpm.conf /usr/local/etc/php-fpm.d/zz-laravel.conf

RUN composer dump-autoload --optimize \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chmod +x docker/entrypoint.sh \
    && chown -R www-data:www-data /var/www/html \
    && ln -sf /dev/stdout /var/log/nginx/access.log \
    && ln -sf /dev/stderr /var/log/nginx/error.log

EXPOSE 8000

ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/conf.d/laravel.conf"]
