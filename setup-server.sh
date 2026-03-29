#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
# SR-Homes Server Setup
# Sets up the webhook listener and deploy pipeline.
# Run once on the server: sudo bash setup-server.sh
# ═══════════════════════════════════════════════════════════════════
set -euo pipefail

REPO_DIR="$(cd "$(dirname "$0")" && pwd)"
INSTALL_DIR="/opt/sr-homes"

echo "═══ SR-Homes Server Setup ═══"

# ─── 1. Install webhook ───────────────────────────────────────────
if ! command -v webhook &>/dev/null; then
    echo "Installing webhook (adnanh/webhook)..."
    go install github.com/adnanh/webhook@latest
    cp "$(go env GOPATH)/bin/webhook" /usr/local/bin/webhook
    echo "  webhook installed at /usr/local/bin/webhook"
else
    echo "  webhook already installed: $(which webhook)"
fi

# ─── 2. Set up repo directory ─────────────────────────────────────
echo "Setting up $INSTALL_DIR..."
mkdir -p "$INSTALL_DIR"

# Symlink or copy deploy files
ln -sf "$REPO_DIR/deploy.sh" "$INSTALL_DIR/deploy.sh"

# Make deploy.sh executable
chmod +x "$REPO_DIR/deploy.sh"

# ─── 3. Configure webhook secret ─────────────────────────────────
SECRET_FILE="$INSTALL_DIR/.webhook-secret"
if [ -f "$SECRET_FILE" ]; then
    WEBHOOK_SECRET=$(cat "$SECRET_FILE")
    echo "  Using existing webhook secret from $SECRET_FILE"
else
    WEBHOOK_SECRET=$(openssl rand -hex 32)
    echo "$WEBHOOK_SECRET" > "$SECRET_FILE"
    chmod 600 "$SECRET_FILE"
    echo "  Generated new webhook secret → $SECRET_FILE"
fi

# Generate hooks.json with the real secret (not the template placeholder)
sed "s/{{WEBHOOK_SECRET}}/$WEBHOOK_SECRET/" "$REPO_DIR/hooks.json" > "$INSTALL_DIR/hooks.json"
echo "  hooks.json written to $INSTALL_DIR/hooks.json"

# Create backup directory
mkdir -p /var/www/backups

# ─── 4. Create systemd service ────────────────────────────────────
echo "Creating systemd service..."
cat > /etc/systemd/system/sr-homes-webhook.service << 'UNIT'
[Unit]
Description=SR-Homes Deploy Webhook
After=network.target

[Service]
Type=simple
ExecStart=/usr/local/bin/webhook -hooks /opt/sr-homes/hooks.json -port 9000 -verbose
Restart=always
RestartSec=5
User=root
WorkingDirectory=/opt/sr-homes

[Install]
WantedBy=multi-user.target
UNIT

# ─── 5. Create log file ──────────────────────────────────────────
touch /var/log/sr-homes-deploy.log
chmod 664 /var/log/sr-homes-deploy.log

# ─── 6. Enable and start ─────────────────────────────────────────
systemctl daemon-reload
systemctl enable sr-homes-webhook
systemctl restart sr-homes-webhook

echo ""
echo "═══ Setup Complete ═══"
echo ""
echo "Webhook listening on port 9000"
echo "  Endpoint: http://localhost:9000/hooks/sr-homes-deploy"
echo ""
echo "IMPORTANT: Configure this secret in GitHub → Settings → Webhooks:"
echo "  Secret: $WEBHOOK_SECRET"
echo ""
echo "GitHub Webhook URL:"
echo "  http://<SERVER-IP>:9000/hooks/sr-homes-deploy"
echo ""
echo "Test with:"
echo "  curl -X POST http://localhost:9000/hooks/sr-homes-deploy \\"
echo "    -H 'Content-Type: application/json' \\"
echo "    -H \"X-Hub-Signature-256: sha256=\$(echo -n '{\"ref\": \"refs/heads/main\"}' | openssl dgst -sha256 -hmac '$WEBHOOK_SECRET' | awk '{print \$2}')\" \\"
echo "    -d '{\"ref\": \"refs/heads/main\", \"after\": \"test\"}'"
echo ""
echo "Logs:"
echo "  journalctl -u sr-homes-webhook -f"
echo "  tail -f /var/log/sr-homes-deploy.log"
