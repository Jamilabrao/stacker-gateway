# Imagem para composer install no host (update.sh / install.sh).
# Espelha extensões do Dockerfile principal — evita falha de platform check (ex.: ext-gd).
FROM php:8.3-cli-alpine

RUN apk add --no-cache \
    git unzip libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev oniguruma-dev icu-dev libxml2-dev $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd zip intl bcmath pcntl exif \
    && pecl install redis \
    && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
