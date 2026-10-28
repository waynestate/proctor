#!/bin/sh
set -e

php artisan config:cache
php artisan migrate:fresh --seed
php artisan route:cache
