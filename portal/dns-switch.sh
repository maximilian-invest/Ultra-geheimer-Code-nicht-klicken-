#!/bin/bash
# DNS Switch Script for SR-Homes Kundenportal
# Run this AFTER changing DNS A record for kundenportal.sr-homes.at to 187.124.166.153

echo '=== SR-Homes DNS Switch ==='
echo 'Step 1: Checking DNS propagation...'
dig +short kundenportal.sr-homes.at

echo ''
echo 'Step 2: Obtaining SSL certificate...'
certbot --nginx -d kundenportal.sr-homes.at --non-interactive --agree-tos -m maximilian@hoelzl.investments

echo ''
echo 'Step 3: Testing nginx config...'
nginx -t

echo ''
echo 'Step 4: Reloading nginx...'
systemctl reload nginx

echo ''
echo 'Step 5: Verifying HTTPS...'
curl -sI https://kundenportal.sr-homes.at/api/ping 2>&1 | head -5

echo ''
echo '=== DNS Switch Complete ==='
echo 'Verify: https://kundenportal.sr-homes.at/admin'
