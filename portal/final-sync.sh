#!/bin/bash
# Final data sync from old server before DNS switch
echo '=== Final Data Sync ==='
cd /var/www/srhomes
php artisan migrate:from-legacy --force
echo '=== Sync Complete ==='
