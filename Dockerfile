# syntax=docker/dockerfile:1
FROM php:8.4-apache-bookworm AS runtime

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql \
    && apt-mark manual libpq5 \
    && apt-get purge -y --auto-remove libpq-dev \
    && a2enmod rewrite \
    && sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
    && mkdir -p /var/run/apache2 /var/lock/apache2 \
    && chown -R www-data:www-data /var/run/apache2 /var/lock/apache2 /var/log/apache2 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

FROM runtime AS dependencies
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress \
    --no-scripts --no-autoloader

COPY app app
COPY bootstrap bootstrap
COPY config config
COPY database database
COPY routes routes
COPY artisan ./
RUN mkdir -p database/factories database/seeders \
    && composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && composer check-platform-reqs --no-dev

FROM runtime AS production
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=info \
    LOG_STDERR_FORMATTER=Monolog\\Formatter\\JsonFormatter \
    CACHE_STORE=file \
    QUEUE_CONNECTION=sync

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/production.ini
COPY --from=dependencies /var/www/html/vendor vendor
COPY --from=dependencies /var/www/html/bootstrap bootstrap
COPY app app
COPY config config
COPY database/migrations database/migrations
COPY routes routes
COPY public public
COPY artisan ./
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown root:root /var/www/html \
    && chmod 0755 /var/www/html \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R u+rwX,go-rwx storage bootstrap/cache

USER www-data
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8080/up") === false ? 1 : 0);'
CMD ["apache2-foreground"]
