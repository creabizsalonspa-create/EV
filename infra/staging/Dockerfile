# syntax=docker/dockerfile:1.7
FROM composer:2@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc AS dependencies
WORKDIR /build
COPY services/business-api/composer.json services/business-api/composer.lock ./
RUN --mount=type=secret,id=ev_ca_bundle,required=false \
    if [ -f /run/secrets/ev_ca_bundle ]; then export COMPOSER_CAFILE=/run/secrets/ev_ca_bundle; fi; \
    composer install --no-dev --no-interaction --prefer-dist --classmap-authoritative --no-scripts

FROM php:8.5.12-apache AS application
RUN apt-get update \
 && apt-get install -y --no-install-recommends libicu-dev libpq-dev libzip-dev libonig-dev \
 && docker-php-ext-install -j2 pdo_pgsql intl zip mbstring opcache \
 && a2enmod rewrite headers \
 && rm -rf /var/lib/apt/lists/*
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf \
 && printf '<Directory /var/www/html/public>\nAllowOverride All\nRequire all granted\n</Directory>\n' > /etc/apache2/conf-available/ev.conf \
 && a2enconf ev
WORKDIR /var/www/html
COPY services/business-api/ ./
COPY --from=dependencies /build/vendor/ ./vendor/
RUN rm -f bootstrap/cache/*.php \
 && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache \
 && php artisan package:discover --no-interaction
EXPOSE 80
HEALTHCHECK --interval=15s --timeout=5s --retries=5 CMD php -r '$s=@file_get_contents("http://127.0.0.1/up");exit($s===false?1:0);'
CMD ["apache2-foreground"]
