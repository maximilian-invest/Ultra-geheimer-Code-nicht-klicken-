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

# 2. Sync portal (exclude vendor, node_modules, .env, storage uploads/logs/cache)
rsync -av --delete \
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

# 3. Sync website (React SPA)
rsync -av --delete \
  "$DEPLOY_DIR/website/" "$WEBSITE_DIR/"
echo "✓ Website synced"

# 3b. Sync website-v2 (Vanilla HTML - the live website)
if [ -d "$DEPLOY_DIR/website-v2" ]; then
  rsync -av --delete \
    "$DEPLOY_DIR/website-v2/" "$WEBSITE_V2_DIR/"
  echo "✓ Website v2 synced"
fi

# 4. Build portal (Vue/JS)
cd "$PORTAL_DIR"
if [ -f "package.json" ]; then
  npm ci --quiet
  npm run build
  echo "✓ Portal built"
fi

# 5. Clear Laravel cache
php artisan config:clear 2>/dev/null || true
php artisan cache:clear 2>/dev/null || true
php artisan view:clear 2>/dev/null || true
echo "✓ Laravel cache cleared"

# 6. Reload PHP-FPM to clear OPcache
systemctl reload php8.3-fpm 2>/dev/null || service php8.3-fpm reload 2>/dev/null || true
echo "✓ PHP-FPM reloaded"

echo "=== Deploy complete ==="
