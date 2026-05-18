#!/usr/bin/env bash
#
# lookup-dns.sh — pull DNS query history for a customer or IP.
# Run on the VPS (root@103.187.22.131) where dnsmasq-resolver runs.
#
# Usage:
#   ./lookup-dns.sh <username|ip> [hours-back]
#
# Examples:
#   ./lookup-dns.sh shawon@ahad.net              # last 24h
#   ./lookup-dns.sh shawon@ahad.net 6            # last 6h
#   ./lookup-dns.sh 172.16.16.243 48             # last 48h
#
# Output:
#   timestamp | client-ip | qtype | queried-domain
#
# Reads from the dnsmasq query log file (and rotated .gz siblings),
# which is bind-mounted from the dnsmasq-resolver container. PHP's
# Browsing-History admin tab reads the SAME file.
#
# Limitations:
#   - Username → IP resolution uses Mikrotik's CURRENT /ppp/active list.
#     If the customer is offline, lookup falls back to "no current IP".
#     For historical name→IP mapping, you need PPPoE session-event
#     logging — not implemented in this patch.
#   - Retention is bounded by logrotate (7 days). Beyond that, gone.
#   - Only DNS queries are logged — full URLs of HTTPS sites are NOT
#     captured.

set -euo pipefail

# ============================================================================
# CONFIG
# ============================================================================
LOG_DIR="${LOG_DIR:-/opt/phpnuxbill/dnsmasq-resolver/logs}"
LOG_FILE="${LOG_FILE:-$LOG_DIR/queries.log}"
MIKROTIK_HOST="${MIKROTIK_HOST:-admin@10.99.0.2}"
SSH_OPTS="${SSH_OPTS:--o ConnectTimeout=5 -o BatchMode=yes}"

# ============================================================================
# ARGUMENT PARSING
# ============================================================================
if [[ $# -lt 1 ]]; then
    cat >&2 <<EOF
Usage: $0 <username|ip> [hours-back]

Examples:
  $0 shawon@ahad.net              # last 24 hours
  $0 shawon@ahad.net 6            # last 6 hours
  $0 172.16.16.243 48             # last 48 hours
EOF
    exit 2
fi

INPUT="$1"
HOURS="${2:-24}"

if ! [[ "$HOURS" =~ ^[0-9]+$ ]] || (( HOURS < 1 || HOURS > 168 )); then
    echo "ERROR: hours-back must be an integer between 1 and 168 (7 days max)" >&2
    exit 2
fi

if [[ ! -d "$LOG_DIR" ]]; then
    echo "ERROR: log directory $LOG_DIR not found — is dnsmasq-resolver running?" >&2
    exit 1
fi

# ============================================================================
# RESOLVE INPUT TO IP
# ============================================================================
ip_re='^([0-9]{1,3}\.){3}[0-9]{1,3}$'

if [[ "$INPUT" =~ $ip_re ]]; then
    CLIENT_IP="$INPUT"
    CLIENT_LABEL="(direct IP lookup)"
else
    echo "Looking up current PPPoE IP for '$INPUT'..." >&2
    if ! command -v ssh >/dev/null 2>&1; then
        echo "ERROR: ssh not installed; cannot reach Mikrotik" >&2
        exit 1
    fi

    PPPOE_OUTPUT=$(ssh $SSH_OPTS "$MIKROTIK_HOST" \
        "/ppp active print where name=\"$INPUT\"" 2>/dev/null || true)

    CLIENT_IP=$(echo "$PPPOE_OUTPUT" \
        | grep -oE 'address=([0-9]{1,3}\.){3}[0-9]{1,3}' \
        | head -1 \
        | cut -d= -f2 || true)

    if [[ -z "$CLIENT_IP" ]]; then
        cat >&2 <<EOF
ERROR: customer '$INPUT' is not currently online (no entry in /ppp active).
       For historical lookups when the customer is offline, supply their
       last-known IP directly:
         $0 <ip-address> [hours-back]
EOF
        exit 1
    fi

    CLIENT_LABEL="$INPUT → $CLIENT_IP"
fi

# ============================================================================
# QUERY THE LOG (current + rotated, both plain and gzipped)
# ============================================================================
# dnsmasq line format (with log-queries on):
#   <syslog-ts> dnsmasq[pid]: <id> <client-ip>/<port> query[X] <name> from <upstream>
#
# Example:
#   May 18 14:30:01 dnsmasq[42]: 1234 172.16.16.243/55432 query[A] youtube.com from 10.99.0.1
#
# Time-filtering against `hours-back` is best-effort — we use file mtime
# as a coarse filter for old rotations, then read everything in scope.
# Precise per-line time filtering happens in the awk pass below.

echo "==> DNS history for $CLIENT_LABEL (last ${HOURS}h)" >&2
echo "==> Reading from: $LOG_DIR/queries.log*" >&2
echo >&2

SINCE_EPOCH=$(date -u -d "${HOURS} hours ago" +%s)

# Collect candidate files: current log + rotated copies (.1, .2.gz, ...)
# Sort newest first; stop scanning files older than the window.
shopt -s nullglob
FILES=("$LOG_FILE" "$LOG_FILE".[0-9]* )
shopt -u nullglob

zcat_or_cat() {
    local f="$1"
    case "$f" in
        *.gz)  zcat -- "$f" ;;
        *)     cat  -- "$f" ;;
    esac
}

MATCHED=0
for f in "${FILES[@]}"; do
    [[ -e "$f" ]] || continue
    # Skip files entirely older than the window (mtime-based fast filter)
    FILE_MTIME=$(stat -c %Y -- "$f" 2>/dev/null || echo 0)
    if (( FILE_MTIME < SINCE_EPOCH - 86400 )); then
        continue
    fi

    while IFS= read -r line; do
        # Quick string-match before regex — much faster on big files
        [[ "$line" == *"${CLIENT_IP}/"* ]] || continue
        [[ "$line" == *"query["* ]]         || continue

        # Parse "May 18 14:30:01" → epoch and skip if outside window
        line_ts=$(printf '%s' "$line" | awk '{print $1, $2, $3}')
        line_epoch=$(date -d "$line_ts" +%s 2>/dev/null || echo 0)
        (( line_epoch >= SINCE_EPOCH )) || continue

        # Extract qtype + qname
        qtype=$(printf '%s' "$line" | grep -oE 'query\[[A-Z]+\]' | head -1)
        qname=$(printf '%s' "$line" | awk -F'query\\[[A-Z]+\\] ' '{print $2}' | awk '{print $1}')
        [[ -n "$qname" ]] || continue

        printf '%s  %s  %-12s  %s\n' "$line_ts" "$CLIENT_IP" "$qtype" "$qname"
        MATCHED=$((MATCHED + 1))
    done < <(zcat_or_cat "$f")
done | sort -u

echo >&2
echo "==> ${MATCHED} matching log entries" >&2
