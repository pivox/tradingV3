FROM php:8.3-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git unzip zip curl sqlite3 libsqlite3-dev \
    libicu-dev libxml2-dev libzip-dev \
    && docker-php-ext-install intl pdo pdo_mysql pdo_sqlite opcache zip sockets \
    && pecl install redis && docker-php-ext-enable redis

# Set memory limit
COPY php/conf.d/memory-limit.ini /usr/local/etc/php/conf.d/memory-limit.ini

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Set working directory
WORKDIR /var/www/html

# Copy project files
COPY . .

# Disable auto-scripts execution (e.g. cache:clear) during image build
RUN composer install --no-scripts --no-interaction --optimize-autoloader
