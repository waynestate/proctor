#!/bin/sh
set -e

php artisan config:cache
php artisan migrate --force
php artisan route:cache
