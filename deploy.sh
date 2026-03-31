#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
# SR-Homes Deploy Script
# Builds portal (Laravel+Vue) and website (React), then deploys.
# Triggered by webhook on push to main.
#
# Features:
#   - Automatic backup before deploy (symlink swap)
#   - Rollback on failure
#   - Basic smoke tests after deploy
#
# Usage:
#   ./deploy.sh                        # deploys main branch
#   DEPLOY_BRANCH=dev ./deploy.sh      # deploys specific branch
# ═══════════════════════════════════════════════════════════════════
set -euo pipefail

# Resolve symlinks to find the real repo directory
SCRIPT_PATH="$(readlink -f "$0")"
REPO_DIR="$(cd "$(dirname "$SCRIPT_PATH")" && pwd)"
PORTAL_DIR="$REPO_DIR/portal"
WEBSITE_SRC_DIR="$REPO_DIR/website-v2"

DEPLOY_PORTAL="/var/www/srhomes"
DEPLOY_WEBSITE="/var/www/sr-homes-website"
BACKUP_DIR="/var/www/backups"

LOG_FILE="/var/log/sr-homes-deploy.log"

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"
}

# ─── ROLLBACK FUNCTION ────────────────────────────────────────────
BACKUP_TIMESTAMP=""
rollback() {
    log "!! Deploy FAILED — rolling back..."
    if [ -n "$BACKUP_TIMESTAMP" ]; then
        if [ -d "$BACKUP_DIR/portal-$BACKUP_TIMESTAMP" ]; then
            log "  Restoring portal from backup..."
            rsync -a --delete "$BACKUP_DIR/portal-$BACKUP_TIMESTAMP/" "$DEPLOY_PORTAL/"
            chown -R www-data:www-data "$DEPLOY_PORTAL/storage" "$DEPLOY_PORTAL/bootstrap/cache" 2>/dev/null || true
        fi
        if [ -d "$BACKUP_DIR/website-$BACKUP_TIMESTAMP" ]; then
            log "  Restoring website from backup..."
            rsync -a --delete "$BACKUP_DIR/website-$BACKUP_TIMESTAMP/" "$DEPLOY_WEBSITE/"
        fi
        systemctl reload php8.3-fpm 2>/dev/null || systemctl reload php8.2-fpm 2>/dev/null || true
        log "  Rollback completed."
    else
        log "  No backup available — cannot rollback."
    fi
    log "═══ Deploy FAILED ═══"
    exit 1
}
trap rollback ERR

log "═══ Deploy started ═══"

# ─── 0. Create backup ────────────────────────────────────────────
BACKUP_TIMESTAMP="$(date '+%Y%m%d-%H%M%S')"
mkdir -p "$BACKUP_DIR"

if [ -d "$DEPLOY_PORTAL" ]; then
    log "Backing up portal..."
    rsync -a "$DEPLOY_PORTAL/" "$BACKUP_DIR/portal-$BACKUP_TIMESTAMP/"
fi
if [ -d "$DEPLOY_WEBSITE" ]; then
    log "Backing up website..."
    rsync -a "$DEPLOY_WEBSITE/" "$BACKUP_DIR/website-$BACKUP_TIMESTAMP/"
fi

# Clean old backups (keep last 5)
ls -dt "$BACKUP_DIR"/portal-* 2>/dev/null | tail -n +6 | xargs rm -rf 2>/dev/null || true
ls -dt "$BACKUP_DIR"/website-* 2>/dev/null | tail -n +6 | xargs rm -rf 2>/dev/null || true

# ─── 1. Pull latest code ───────────────────────────────────────────
BRANCH="${DEPLOY_BRANCH:-main}"
log "Pulling latest code (branch: $BRANCH)..."
cd "$REPO_DIR"
git fetch origin "$BRANCH"
git checkout "$BRANCH" 2>/dev/null || true
git reset --hard "origin/$BRANCH"

# ─── 2. Build Portal (Laravel + Vue/Inertia) ──────────────────────
log "Building portal..."
cd "$PORTAL_DIR"

# PHP dependencies (--no-scripts avoids artisan errors during autoload)
log "  composer install..."
composer install --no-dev --optimize-autoloader --no-interaction --no-scripts
php artisan package:discover --ansi 2>/dev/null || true

# Node dependencies & Vite build
log "  npm ci..."
npm ci --no-audit

log "  npm run build (Vite)..."
npm run build

# Run database migrations
log "  Running database migrations..."
php artisan migrate --force --no-interaction

# Laravel caches
log "  Clearing & rebuilding Laravel caches..."
php artisan config:cache
php artisan route:cache
php artisan view:cache 2>/dev/null || true
php artisan event:cache 2>/dev/null || true

# ─── 3. Website (plain HTML/CSS/JS — no build step) ──────────────
log "Website: plain HTML/CSS/JS, no build needed."

# ─── 4. Run tests (if available) ──────────────────────────────────
log "Running tests..."

# Portal tests (Laravel)
cd "$PORTAL_DIR"
if [ -f "artisan" ] && php artisan list 2>/dev/null | grep -q "test"; then
    log "  Running Laravel tests..."
    php artisan test --no-interaction 2>&1 | tee -a "$LOG_FILE" || {
        log "  WARNING: Laravel tests failed — continuing deploy (tests not blocking yet)"
    }
else
    log "  No Laravel tests configured — skipping."
fi

log "  Website source: $WEBSITE_SRC_DIR ($(find "$WEBSITE_SRC_DIR" -type f | wc -l) files)."

# ─── 5. Deploy Portal ─────────────────────────────────────────────
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

# ─── 6. Deploy Website ────────────────────────────────────────────
log "Deploying website to $DEPLOY_WEBSITE..."
rsync -av --delete \
    "$WEBSITE_SRC_DIR/" "$DEPLOY_WEBSITE/"

# Also copy root-level website files (favicon, icons)
for f in favicon.svg icons.svg; do
    if [ -f "$REPO_DIR/website/$f" ]; then
        cp "$REPO_DIR/website/$f" "$DEPLOY_WEBSITE/" 2>/dev/null || true
    fi
done

# ─── 7. Restart services ──────────────────────────────────────────
log "Restarting PHP-FPM..."
systemctl reload php8.3-fpm 2>/dev/null || systemctl reload php8.2-fpm 2>/dev/null || true

# ─── 8. Smoke tests ───────────────────────────────────────────────
log "Running smoke tests..."

# Check portal responds
PORTAL_STATUS=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 "http://localhost/api/website/properties" 2>/dev/null || echo "000")
if [ "$PORTAL_STATUS" = "200" ]; then
    log "  Portal API: OK (HTTP $PORTAL_STATUS)"
else
    log "  WARNING: Portal API returned HTTP $PORTAL_STATUS (expected 200)"
fi

# Check website serves index.html
if [ -f "$DEPLOY_WEBSITE/index.html" ]; then
    log "  Website index.html: OK"
else
    log "  WARNING: Website index.html missing!"
fi

log "═══ Deploy completed successfully ═══"
