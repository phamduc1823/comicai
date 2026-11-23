# ==============================================================================
# ComicAI Backend Dockerfile — PHP 8.3 FPM Alpine
# Optimized for Laravel 11 API, Queue Workers, and Reverb WebSocket
# ==============================================================================

FROM php:8.3-fpm-alpine

# Set working directory
WORKDIR /var/www/html

# Install system dependencies & build tools
RUN apk add --no-cache \
    bash \
    curl \
    git \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libwebp-dev \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    linux-headers \
    mysql-client \
    su-exec \
    $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        bcmath \
        gd \
        intl \
        zip \
        exif \
        opcache \
        pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS \
    && rm -rf /tmp/pear

# Install Composer from official image
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# Copy PHP production configuration
COPY ./docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY ./docker/php/custom.ini /usr/local/etc/php/conf.d/custom.ini

# Copy composer manifest first to cache dependency install
COPY composer.json composer.lock* ./

# Install Composer dependencies (excluding dev for production image)
ARG APP_ENV=production
RUN if [ "$APP_ENV" = "production" ]; then \
        composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts; \
    else \
        composer install --no-interaction --prefer-dist --no-scripts; \
    fi

# Copy application source code
COPY . .

# Copy docker entrypoint script
COPY ./docker/entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Set directory permissions for Laravel runtime
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Healthcheck for PHP-FPM
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD php-fpm-healthcheck || exit 0

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]
