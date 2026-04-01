#!/bin/sh
set -e

# Clear all caches on deploy
rm -rf /var/www/html/var/cache/di/*.php /var/www/html/var/cache/twig/* 2>/dev/null || true

# Reset opcache if available
php -r "if(function_exists('opcache_reset')) { opcache_reset(); echo 'opcache cleared'; } else { echo 'no opcache'; }" 2>/dev/null || true

# Verify vendor exists (safety net if Docker build failed silently)
if [ ! -f /var/www/html/vendor/autoload.php ]; then
    echo "WARNING: vendor/autoload.php not found. Running composer install..."
    cd /var/www/html && composer install --no-dev --optimize-autoloader --no-interaction
fi

# Wait for MariaDB and initialize database if needed
if [ -n "$DB_HOST" ]; then
    echo "Waiting for MariaDB at $DB_HOST..."
    MAX_TRIES=30
    COUNT=0
    until php -r "
        \$h='${DB_HOST}'; \$u='${DB_USERNAME}'; \$p='${DB_PASSWORD}'; \$d='${DB_DATABASE}';
        try { new PDO(\"mysql:host=\$h;dbname=\$d\", \$u, \$p, [PDO::ATTR_TIMEOUT => 2]); exit(0); }
        catch(Exception \$e) { exit(1); }
    " 2>/dev/null; do
        COUNT=$((COUNT + 1))
        if [ "$COUNT" -ge "$MAX_TRIES" ]; then
            echo "ERROR: MariaDB not reachable after ${MAX_TRIES} attempts"
            break
        fi
        echo "  Attempt $COUNT/$MAX_TRIES - waiting 2s..."
        sleep 2
    done

    # Check if tables exist, if not run schema + seed
    TABLES=$(php -r "
        \$h='${DB_HOST}'; \$u='${DB_USERNAME}'; \$p='${DB_PASSWORD}'; \$d='${DB_DATABASE}';
        try {
            \$pdo = new PDO(\"mysql:host=\$h;dbname=\$d\", \$u, \$p);
            \$r = \$pdo->query('SHOW TABLES');
            echo \$r->rowCount();
        } catch(Exception \$e) { echo '0'; }
    " 2>/dev/null)

    if [ "$TABLES" = "0" ]; then
        echo "Database is empty. Initializing schema and seed data..."
        php -r "
            \$h='${DB_HOST}'; \$u='${DB_USERNAME}'; \$p='${DB_PASSWORD}'; \$d='${DB_DATABASE}';
            \$pdo = new PDO(\"mysql:host=\$h;dbname=\$d\", \$u, \$p);
            \$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            foreach (['database/schema.sql', 'database/seed.sql'] as \$file) {
                \$path = '/var/www/html/' . \$file;
                if (file_exists(\$path)) {
                    echo \"Running \$file...\\n\";
                    \$sql = file_get_contents(\$path);
                    \$pdo->exec(\$sql);
                    echo \"  Done.\\n\";
                }
            }
        "
        echo "Database initialization complete."
    else
        echo "Database already has $TABLES tables, skipping init."
        # Run pending migrations
        for migration in /var/www/html/database/migrations/*.sql; do
            if [ -f "$migration" ]; then
                BASENAME=$(basename "$migration")
                echo "Running migration: $BASENAME"
                php -r "
                    \$h='${DB_HOST}'; \$u='${DB_USERNAME}'; \$p='${DB_PASSWORD}'; \$d='${DB_DATABASE}';
                    \$pdo = new PDO(\"mysql:host=\$h;dbname=\$d\", \$u, \$p);
                    \$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    \$sql = file_get_contents('$migration');
                    try { \$pdo->exec(\$sql); echo \"  Done.\n\"; }
                    catch (Exception \$e) { echo \"  Skipped (\" . \$e->getMessage() . \")\n\"; }
                " 2>/dev/null || true
            fi
        done
    fi
fi

# Execute the CMD
exec "$@"
