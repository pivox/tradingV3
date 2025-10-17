FROM php:8.2-cli-alpine

RUN set -eux; \
    apk add --no-cache git unzip postgresql-dev; \
    docker-php-ext-install -j$(nproc) opcache pdo_pgsql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html

COPY composer.json symfony.lock* ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress

COPY . .

ENV APP_ENV=prod
CMD ["php","bin/console","ws:run"]

