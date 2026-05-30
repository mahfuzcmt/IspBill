#!/bin/bash
# Deploy Due Warning System for PPPoE Users
# Run this script on the server: bash deploy-due-warning.sh

set -e
echo "=== Deploying Due Warning System ==="

# 1. Copy due-notice controller
echo "[1/5] Copying due-notice.php controller..."
cp /opt/phpnuxbill/overrides/system/controllers/due-notice.php /opt/phpnuxbill/overrides/system/controllers/due-notice.php 2>/dev/null || \
mkdir -p /opt/phpnuxbill/overrides/system/controllers

# The file is already mounted via Docker bind mount from overrides directory

# 2. Copy due-sync script
echo "[2/5] Copying due-sync.php script..."
# This runs outside container via cron

# 3. Update nginx configuration
echo "[3/5] Updating nginx configuration..."
NGINX_CONF="/opt/phpnuxbill/router-proxy/nginx.conf"

# Check if due-warning server block already exists
if grep -q "listen 8089" "$NGINX_CONF"; then
    echo "   Port 8089 server block already exists"
else
    echo "   Adding port 8089 server block for due warnings..."
    cat >> "$NGINX_CONF" << 'EOF'

# Server for due warning notices (port 8089)
server {
    listen 8089;
    server_name _;

    location / {
        set $original_url $scheme://$host$request_uri;
        proxy_pass http://phpnuxbill:80/index.php?_route=due-notice&url=$original_url;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Original-URL $original_url;
    }
}
EOF
fi

# 4. Restart nginx container
echo "[4/5] Restarting nginx container..."
docker restart router-proxy

# 5. Set up cron job for due-sync
echo "[5/5] Setting up cron job for due-sync..."
CRON_CMD="*/5 * * * * docker exec phpnuxbill php /var/www/html/system/due-sync.php >> /var/log/due-sync.log 2>&1"

# Check if cron job already exists
if crontab -l 2>/dev/null | grep -q "due-sync.php"; then
    echo "   Cron job already exists"
else
    echo "   Adding cron job (runs every 5 minutes)..."
    (crontab -l 2>/dev/null; echo "$CRON_CMD") | crontab -
fi

echo ""
echo "=== Deployment Complete ==="
echo ""
echo "The due warning system is now active:"
echo "  - Due users are synced to Mikrotik every 5 minutes"
echo "  - HTTP requests from due users redirect to payment notice"
echo "  - Notice shows max 4 times per day per user"
echo "  - Auto-redirects to original site after 30 seconds"
echo ""
echo "To test: Add a test IP to due-warning list on Mikrotik"
echo "  /ip firewall address-list add list=due-warning address=10.0.0.x comment=\"Test\""
