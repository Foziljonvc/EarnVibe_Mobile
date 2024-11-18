#!/bin/bash
set -e

# Kutish funksiyasi
wait_for_postgres() {
    echo "Waiting for postgres..."
    while ! nc -z postgres 5432; do
      sleep 1
    done
    echo "PostgreSQL started"
}

# PostgreSQL ishga tushishini kutish
wait_for_postgres

# Composer install
composer install --no-interaction --no-dev --optimize-autoloader

# Storage ruxsatlarini sozlash
chown -R www-data:www-data /var/www/storage
chmod -R 775 /var/www/storage /var/www/bootstrap/cache

# Komandalarni bajarish
php artisan key:generate --force
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
php artisan migrate --force

# PHP-FPM ni ishga tushirish
exec php-fpm
