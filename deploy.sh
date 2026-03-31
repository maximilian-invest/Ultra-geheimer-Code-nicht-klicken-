#!/bin/bash
set -e

DEPLOY_DIR="/var/www/sr-deploy"
PORTAL_DIR="/var/www/srhomes"
WEBSITE_DIR="/var/www/sr-homes-website"
WEBSITE_V2_DIR="/var/www/sr-homes-v2"

echo "=== SR-Homes Deploy ==="
echo "$(date)"

# 1. Pull latest code
cd "$DEPLOY_DIR"
git fetch origin
git reset --hard origin/main
echo "✓ Code pulled"

# 2. Sync portal PHP/config files (no build artifacts)
mkdir -p "$PORTAL_DIR"
rsync -a --delete \
  --exclude="vendor/" \
  --exclude="node_modules/" \
  --exclude=".env" \
  --exclude=".env.backup" \
  --exclude=".env.production" \
  --exclude="storage/app/public/property_files/" \
  --exclude="storage/app/public/property_images/" \
  --exclude="storage/app/public/website/" \
  --exclude="storage/app/public/kaufanbote/" \
  --exclude="storage/app/public/global_files/" \
  --exclude="storage/logs/" \
  --exclude="storage/framework/cache/" \
  --exclude="storage/framework/sessions/" \
  --exclude="storage/framework/views/" \
  --exclude="storage/*.key" \
  --exclude="public/build/" \
  --exclude="public/hot" \
  --exclude="public/storage" \
  --exclude="auth.json" \
  "$DEPLOY_DIR/portal/" "$PORTAL_DIR/"
echo "✓ Portal synced"

# 3. Build portal Vue/JS
cd "$PORTAL_DIR"
if [ -f "package.json" ]; then
  npm ci --quiet 2>&1 | tail -3
  npm run build 2>&1 | tail -5
  echo "✓ Portal JS built"
fi

# 4. Clear Laravel cache + reload PHP-FPM (clears OPcache)
php artisan config:clear 2>/dev/null || true
php artisan cache:clear 2>/dev/null || true
php artisan view:clear 2>/dev/null || true
systemctl reload php8.3-fpm 2>/dev/null || service php8.3-fpm reload 2>/dev/null || pkill -USR2 php-fpm 2>/dev/null || true
echo "✓ PHP cache cleared"

# 5. Sync website-v2 (plain HTML/CSS/JS - the live marketing site)
if [ -d "$DEPLOY_DIR/website-v2" ]; then
  mkdir -p "$WEBSITE_V2_DIR"
  rsync -a --delete "$DEPLOY_DIR/website-v2/" "$WEBSITE_V2_DIR/"
  echo "✓ Website v2 synced"
fi

# 6. Sync legacy website if it exists
if [ -d "$DEPLOY_DIR/website" ]; then
  mkdir -p "$WEBSITE_DIR"
  rsync -a --delete "$DEPLOY_DIR/website/" "$WEBSITE_DIR/" 2>/dev/null || true
fi

echo "=== Deploy complete ==="
