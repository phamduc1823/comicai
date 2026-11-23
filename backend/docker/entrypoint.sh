#!/bin/sh
set -e

# ==============================================================================
# ComicAI Backend Container Entrypoint
# Prepares storage directories, symlinks, and sets up permissions
# ==============================================================================

# Ensure storage directories exist
mkdir -p /var/www/html/storage/app/public \
         /var/www/html/storage/framework/cache/data \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views \
         /var/www/html/storage/logs \
         /var/www/html/bootstrap/cache

# Ensure ownership and permissions
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Create storage symlink if not exists
if [ ! -L /var/www/html/public/storage ]; then
    php artisan storage:link --quiet || true
fi

# Execute main container command
exec "$@"
