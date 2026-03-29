#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
# SR-Homes Deploy Script
# Builds portal (Laravel+Vue) and website (React), then deploys.
# Triggered by webhook on push to main.
# ═══════════════════════════════════════════════════════════════════
set -euo pipefail

REPO_DIR="$(cd "$(dirname "$0")" && pwd)"
PORTAL_DIR="$REPO_DIR/portal"
WEBSITE_SRC_DIR="$REPO_DIR/website/src"
WEBSITE_DIST_DIR="$REPO_DIR/website/dist"

DEPLOY_PORTAL="/var/www/srhomes"
DEPLOY_WEBSITE="/var/www/sr-homes-website"

LOG_FILE="/var/log/sr-homes-deploy.log"

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"
}

log "═══ Deploy started ═══"

# ─── 1. Pull latest code ───────────────────────────────────────────
log "Pulling latest code..."
cd "$REPO_DIR"
git pull origin main

# ─── 2. Build Portal (Laravel + Vue/Inertia) ──────────────────────
log "Building portal..."
cd "$PORTAL_DIR"

# PHP dependencies
log "  composer install..."
composer install --no-dev --optimize-autoloader --no-interaction

# Node dependencies & Vite build
log "  npm ci..."
npm ci --no-audit

log "  npm run build (Vite)..."
npm run build

# Laravel caches
log "  Clearing & rebuilding Laravel caches..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# ─── 3. Build Website (React SPA) ─────────────────────────────────
log "Building website..."
cd "$WEBSITE_SRC_DIR"

log "  npm ci..."
npm ci --no-audit

log "  npm run build (Vite)..."
npm run build

# ─── 4. Deploy Portal ─────────────────────────────────────────────
log "Deploying portal to $DEPLOY_PORTAL..."
rsync -av --delete \
    --exclude='.env' \
    --exclude='storage/app/public/property_files/' \
    --exclude='storage/app/public/property_images/' \
    --exclude='storage/app/public/website/' \
    --exclude='storage/app/public/kaufanbote/' \
    --exclude='storage/app/public/global_files/' \
    --exclude='storage/logs/' \
    --exclude='storage/framework/sessions/' \
    --exclude='storage/framework/cache/data/' \
    --exclude='node_modules/' \
    --exclude='vendor/' \
    "$PORTAL_DIR/" "$DEPLOY_PORTAL/"

# Copy vendor & built assets (these are needed at runtime)
rsync -av "$PORTAL_DIR/vendor/" "$DEPLOY_PORTAL/vendor/"
rsync -av "$PORTAL_DIR/public/build/" "$DEPLOY_PORTAL/public/build/"

# Ensure storage permissions
chmod -R 775 "$DEPLOY_PORTAL/storage" "$DEPLOY_PORTAL/bootstrap/cache" 2>/dev/null || true
chown -R www-data:www-data "$DEPLOY_PORTAL/storage" "$DEPLOY_PORTAL/bootstrap/cache" 2>/dev/null || true

# ─── 5. Deploy Website ────────────────────────────────────────────
log "Deploying website to $DEPLOY_WEBSITE..."
rsync -av --delete \
    "$WEBSITE_DIST_DIR/" "$DEPLOY_WEBSITE/"

# Also copy root-level website files (index.html, favicon, icons)
for f in index.html favicon.svg icons.svg; do
    if [ -f "$REPO_DIR/website/$f" ]; then
        cp "$REPO_DIR/website/$f" "$DEPLOY_WEBSITE/" 2>/dev/null || true
    fi
done

# ─── 6. Restart services ──────────────────────────────────────────
log "Restarting PHP-FPM..."
systemctl reload php8.3-fpm 2>/dev/null || systemctl reload php8.2-fpm 2>/dev/null || true

log "═══ Deploy completed successfully ═══"
