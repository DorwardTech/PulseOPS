FROM php:8.4-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    libzip-dev \
    oniguruma-dev \
    icu-dev \
    && docker-php-ext-install \
    pdo_mysql \
    mbstring \
    zip \
    intl \
    opcache

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configure Nginx (template — envsubst at runtime)
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf.template
RUN rm -f /etc/nginx/http.d/default.conf.bak

# Configure Supervisord
COPY docker/supervisord.conf /etc/supervisord.conf

# Configure PHP
COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

COPY . .

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/public/uploads

# Create nginx pid directory
RUN mkdir -p /run/nginx

# Default port
ENV APP_PORT=3000
EXPOSE 3000

# Entrypoint: template nginx config then start supervisord
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
