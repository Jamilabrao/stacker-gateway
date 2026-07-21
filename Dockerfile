FROM php:8.3-cli-alpine AS php_base

RUN apk add --no-cache \
    git unzip libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev oniguruma-dev \
    postgresql-client postgresql-dev icu-dev libxml2-dev $PHPIZE_DEPS

RUN pecl install redis \
    && docker-php-ext-enable redis

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd pdo_pgsql zip exif intl opcache pcntl bcmath

COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/99-uploads.ini
# OPcache no CLI: php artisan serve usa SAPI CLI (enable_cli=0 por padrão = lento).
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/90-opcache.ini

# Composer via PHAR (evita COPY --from=composer:2, que falha com tls: bad record MAC em alguns Docker Desktop).
RUN apk add --no-cache curl \
    && curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php \
    && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm -f /tmp/composer-setup.php \
    && composer --version

WORKDIR /var/www/html

FROM php_base AS app

# vendor/ deve existir no contexto (gerado por docker/install-composer-deps.sh no host).
# Evita composer install no build: em alguns VPS o BuildKit não alcança api.github.com.
COPY . .
COPY docker/entrypoint.sh /usr/local/bin/getfy-entrypoint

RUN if [ ! -f vendor/autoload.php ]; then \
      echo "ERRO: vendor/ ausente. Rode na VPS: sh docker/install-composer-deps.sh" >&2; \
      exit 1; \
    fi \
    && chmod +x /usr/local/bin/getfy-entrypoint \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache .docker \
    && chmod -R 777 storage bootstrap/cache .docker

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/getfy-entrypoint"]
CMD ["sh", "-lc", "php artisan serve --host=0.0.0.0 --port=${PORT:-80} --no-reload"]
