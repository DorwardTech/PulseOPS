#!/bin/sh
set -e

# Template the nginx config with environment variables
envsubst '${APP_PORT}' < /etc/nginx/http.d/default.conf.template > /etc/nginx/http.d/default.conf

# Verify vendor exists (safety net if Docker build failed silently)
if [ ! -f /var/www/html/vendor/autoload.php ]; then
    echo "WARNING: vendor/autoload.php not found. Running composer install..."
    cd /var/www/html && composer install --no-dev --optimize-autoloader --no-interaction
fi

# Execute the CMD
exec "$@"
