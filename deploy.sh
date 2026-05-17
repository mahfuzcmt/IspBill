#!/usr/bin/env bash
#
# Deploy IspBill / NetPulse customisations to the production host.
#
# Production setup (as of 2026-05-17):
#   - Container `phpnuxbill-app` runs the upstream `animegasan/phpnuxbill:latest`
#     image *unmodified*.
#   - Every customised file lives at /opt/phpnuxbill/overrides/<path> and is
#     bind-mounted over the corresponding path inside the container via
#     /opt/phpnuxbill/docker-compose.yml.
#   - This script rsyncs the local source tree into the host's overrides/
#     directory and restarts the app container so the new files take effect.
#     No image rebuild is required.
#
# Usage:
#     ./deploy.sh
#     DEPLOY_HOST=user@example.com ./deploy.sh
#     DRY_RUN=1 ./deploy.sh           # show what would change, don't apply
#
# Env:
#     DEPLOY_HOST   default: root@103.187.22.131
#     DEPLOY_DIR    default: /opt/phpnuxbill
#     DRY_RUN       any truthy value runs rsync --dry-run and skips restart
#
# IMPORTANT: rsync runs WITHOUT --delete. Production keeps files this repo
# does not (notably config.php with real credentials), and removing them
# would break the deployment. If you need to remove a stale override, do
# it on the host explicitly.
#
# IMPORTANT: New files added in this repo will land on the host but won't
# take effect until the corresponding bind mount is added to
# /opt/phpnuxbill/docker-compose.yml. Add the mount manually for now.

set -euo pipefail

HOST="${DEPLOY_HOST:-root@103.187.22.131}"
DIR="${DEPLOY_DIR:-/opt/phpnuxbill}"
DRY="${DRY_RUN:-}"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$REPO_ROOT"

if [[ -n "$DRY" ]]; then
    RSYNC_FLAGS="-avzn"
    echo "==> DRY RUN — nothing will be applied"
else
    RSYNC_FLAGS="-avz"
fi

echo "==> Pre-flight: working tree status"
git status --short || true
echo

echo "==> Syncing $REPO_ROOT/ → $HOST:$DIR/overrides/"
rsync $RSYNC_FLAGS \
    --exclude='.git/' \
    --exclude='.claude/' \
    --exclude='.idea/' \
    --exclude='.vscode/' \
    --exclude='.deploy/' \
    --exclude='deploy.sh' \
    --exclude='.dockerignore' \
    --exclude='.gitignore' \
    --exclude='*.sql' \
    --exclude='*.sql.gz' \
    --exclude='*.dump' \
    --exclude='*.log' \
    --exclude='config.php' \
    --exclude='config.php.example' \
    --exclude='system/uploads/' \
    --exclude='system/cache/' \
    --exclude='ui/compiled/' \
    --exclude='ui/cache/' \
    --exclude='docs/' \
    --exclude='qrcode/' \
    "$REPO_ROOT/" "$HOST:$DIR/overrides/"

if [[ -n "$DRY" ]]; then
    echo "==> DRY RUN complete. Re-run without DRY_RUN=1 to apply."
    exit 0
fi

echo
echo "==> Restarting nuxbill-app + nuxbill-cron"
ssh "$HOST" "cd $DIR && docker compose up -d nuxbill-app nuxbill-cron && docker ps --filter name=phpnuxbill --format 'table {{.Names}}\t{{.Status}}'"

echo
echo "==> Done. Verify: http://103.187.22.131:8081/"
echo "    Tail cron logs:    ssh $HOST docker logs -f phpnuxbill-cron"
echo "    Tail app logs:     ssh $HOST docker logs -f phpnuxbill-app"
