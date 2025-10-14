FROM php:8.2-cli-alpine

# Install minimal deps (intl optional)
RUN apk add --no-cache git curl bash

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json /app/composer.json
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress

COPY src /app/src
COPY bin /app/bin

# Entrypoints restreints (on override via docker-compose)
CMD [ "php", "-v" ]
