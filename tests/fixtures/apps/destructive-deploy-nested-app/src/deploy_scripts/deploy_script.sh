#!/bin/sh
cd /var/www/html
composer install --no-dev
php artisan migrate:fresh --seed
