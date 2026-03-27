#!/bin/bash
# Script to clear Laravel cache and optimize CORS configuration

echo "Clearing Laravel cache..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

echo "Optimizing..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "Restarting PHP-FPM (if applicable)..."
sudo systemctl restart php8.2-fpm 2>/dev/null || sudo systemctl restart php8.1-fpm 2>/dev/null || echo "PHP-FPM restart skipped"

echo "Cache cleared successfully!"