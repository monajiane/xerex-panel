FROM php:8.3-fpm-alpine AS base

# System dependencies
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    libzip-dev \
    zip \
    unzip \
    oniguruma-dev \
    icu-dev \
    $PHPIZE_DEPS \
    linux-headers \
    postgresql-dev \
    nginx \
    certbot \
    certbot-nginx \
    supervisor

# PHP extensions
RUN docker-php-ext-install pdo pdo_pgsql intl zip bcmath opcache pcntl sockets

# Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# Node
COPY --from=node:20-alpine /usr/local/bin/node /usr/local/bin/node
COPY --from=node:20-alpine /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm

# Set working dir
WORKDIR /var/www/html

# Install PHP dependencies first (better cache)
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --no-scripts --no-progress || composer install --no-interaction --no-scripts --no-progress

# Copy app
COPY . .

# Build assets
RUN if [ -f package.json ]; then npm install && npm run build; fi

# Permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
RUN chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 8000 9000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
