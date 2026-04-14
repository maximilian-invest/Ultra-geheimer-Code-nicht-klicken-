#!/usr/bin/env bash
# ============================================================================
# SR-Homes Deploy Script
# ----------------------------------------------------------------------------
# Läuft AUF DEM VPS. Holt neuen Code aus git, migriert DB, baut Assets,
# synct website-v2/ nach /var/www/sr-homes-v2/, restartet Services.
#
# Nutzung (auf dem VPS):
#   cd /var/www/srhomes && bash deploy.sh
#
# Nutzung (vom Mac aus):
#   ssh root@187.124.166.153 "cd /var/www/srhomes && bash deploy.sh"
#
# Bei Problemen: Backup in /root/backups/db-pre-deploy-*.sql.gz zurückspielen.
# ============================================================================

set -euo pipefail

PORTAL_DIR="/var/www/srhomes"
WEBSITE_V2_TARGET="/var/www/sr-homes-v2"
BACKUP_DIR="/root/backups"
DB_USER="srhomes"
DB_PASS='SRH_db_2026!portal'
DB_NAME="srhomes_portal"

STAMP="$(date +%Y%m%d_%H%M%S)"
LOG_PREFIX="[deploy $STAMP]"

log() { echo -e "\033[1;36m${LOG_PREFIX}\033[0m $*"; }
ok()  { echo -e "\033[1;32m${LOG_PREFIX} ✓\033[0m $*"; }
err() { echo -e "\033[1;31m${LOG_PREFIX} ✗\033[0m $*" >&2; }

trap 'err "Deploy FAILED at line $LINENO. Backup at $BACKUP_DIR/db-pre-deploy-$STAMP.sql.gz"' ERR

cd "$PORTAL_DIR"

# ----------------------------------------------------------------------------
# 1. DB Backup (Safety-Net BEVOR irgendwas angefasst wird)
# ----------------------------------------------------------------------------
log "Step 1/8: DB backup"
mkdir -p "$BACKUP_DIR"
BACKUP_FILE="$BACKUP_DIR/db-pre-deploy-$STAMP.sql.gz"
mysqldump --no-defaults -h 127.0.0.1 -u "$DB_USER" -p"$DB_PASS" \
    --single-transaction --quick --routines --triggers "$DB_NAME" 2>/dev/null \
    | gzip > "$BACKUP_FILE"
ok "DB backup: $(du -h "$BACKUP_FILE" | cut -f1) at $BACKUP_FILE"

# Alte Backups aufräumen (nur die letzten 20 behalten)
ls -1t "$BACKUP_DIR"/db-pre-deploy-*.sql.gz 2>/dev/null | tail -n +21 | xargs -r rm -f

# ----------------------------------------------------------------------------
# 2. Git Pull
# ----------------------------------------------------------------------------
log "Step 2/8: git pull"
if ! git diff --quiet || ! git diff --cached --quiet; then
    err "WARNING: uncommitted changes on VPS. Stashing..."
    git stash push -m "auto-stash pre-deploy $STAMP"
fi
OLD_HEAD=$(git rev-parse --short HEAD)
git fetch origin
git reset --hard origin/main
NEW_HEAD=$(git rev-parse --short HEAD)
ok "HEAD: $OLD_HEAD -> $NEW_HEAD"

if [ "$OLD_HEAD" = "$NEW_HEAD" ]; then
    log "Already up to date, but continuing with rebuild anyway"
fi

# ----------------------------------------------------------------------------
# 3. Composer (PHP deps, nur falls composer.lock sich geändert hat)
# ----------------------------------------------------------------------------
log "Step 3/8: composer install"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
ok "composer done"

# ----------------------------------------------------------------------------
# 4. NPM + Vite Build
# ----------------------------------------------------------------------------
log "Step 4/8: npm ci + build"
npm ci --silent
npm run build
ok "frontend built"

# ----------------------------------------------------------------------------
# 5. Laravel Migrations
# ----------------------------------------------------------------------------
log "Step 5/8: php artisan migrate"
php artisan migrate --force
ok "migrations applied"

# ----------------------------------------------------------------------------
# 6. Laravel Caches (route, config, view, event)
# ----------------------------------------------------------------------------
log "Step 6/8: rebuild caches"
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
ok "caches rebuilt"

# ----------------------------------------------------------------------------
# 7. Sync website-v2/ nach /var/www/sr-homes-v2/
# ----------------------------------------------------------------------------
log "Step 7/8: rsync website-v2 -> $WEBSITE_V2_TARGET"
if [ -d "$PORTAL_DIR/website-v2" ]; then
    rsync -a --delete \
        --exclude '.git*' \
        "$PORTAL_DIR/website-v2/" "$WEBSITE_V2_TARGET/"
    ok "website-v2 synced ($(find "$WEBSITE_V2_TARGET" -type f | wc -l | tr -d ' ') files)"
else
    log "website-v2/ not in repo, skipping"
fi

# ----------------------------------------------------------------------------
# 8. Services neustarten (Queue Workers + PHP-FPM)
# ----------------------------------------------------------------------------
log "Step 8/8: restart services"
supervisorctl restart all 2>&1 | tail -5 || log "supervisorctl not running (OK if no queue)"
systemctl reload php8.3-fpm 2>&1 || err "php-fpm reload failed (non-fatal)"
ok "services reloaded"

# ----------------------------------------------------------------------------
# Done
# ----------------------------------------------------------------------------
echo ""
ok "DEPLOY COMPLETE: $OLD_HEAD -> $NEW_HEAD"
echo ""
log "Rollback command (if needed):"
echo "  gunzip -c $BACKUP_FILE | mysql --no-defaults -u $DB_USER -p'$DB_PASS' $DB_NAME"
echo "  cd $PORTAL_DIR && git reset --hard $OLD_HEAD && bash deploy.sh"
echo ""
