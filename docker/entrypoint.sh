#!/bin/sh
set -e

# Verify vendor exists (safety net if Docker build failed silently)
if [ ! -f /var/www/html/vendor/autoload.php ]; then
    echo "WARNING: vendor/autoload.php not found. Running composer install..."
    cd /var/www/html && composer install --no-dev --optimize-autoloader --no-interaction
fi

# Execute the CMD
exec "$@"
