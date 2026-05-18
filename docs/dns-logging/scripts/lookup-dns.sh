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
#   timestamp | client-ip | queried-domain
#
# Limitations:
#   - Username → IP resolution uses Mikrotik's CURRENT /ppp/active list.
#     If the customer is offline, lookup falls back to "no current IP".
#     For historical name→IP mapping (e.g. "what was X's IP on Tuesday?"),
#     you need PPPoE session-event logging — not implemented in this patch.
#   - Retention is bounded by docker's log rotation (100m × 7 files).
#     Beyond that window, history is gone.
#   - Only DNS queries are logged — full URLs of HTTPS sites are NOT
#     captured. Page content, search queries, etc. are unobtainable
#     without breaking encryption.

set -euo pipefail

# ============================================================================
# CONFIG
# ============================================================================
CONTAINER="${CONTAINER:-dnsmasq-resolver}"
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

# ============================================================================
# RESOLVE INPUT TO IP
# ============================================================================
# Quick IPv4 sanity check — if it looks like an IP, use it directly.
ip_re='^([0-9]{1,3}\.){3}[0-9]{1,3}$'

if [[ "$INPUT" =~ $ip_re ]]; then
    CLIENT_IP="$INPUT"
    CLIENT_LABEL="(direct IP lookup)"
else
    # Username → current IP via Mikrotik
    echo "Looking up current PPPoE IP for '$INPUT'..." >&2
    if ! command -v ssh >/dev/null 2>&1; then
        echo "ERROR: ssh not installed; cannot reach Mikrotik" >&2
        exit 1
    fi

    # Mikrotik /ppp active print returns one row per active session.
    # We grep for the username and extract the 'address' field.
    PPPOE_OUTPUT=$(ssh $SSH_OPTS "$MIKROTIK_HOST" \
        "/ppp active print where name=\"$INPUT\"" 2>/dev/null || true)

    CLIENT_IP=$(echo "$PPPOE_OUTPUT" \
        | grep -oE 'address=([0-9]{1,3}\.){3}[0-9]{1,3}' \
        | head -1 \
        | cut -d= -f2 || true)

    if [[ -z "$CLIENT_IP" ]]; then
        cat >&2 <<EOF
ERROR: customer '$INPUT' is not currently online (no entry in /ppp active).
       For historical lookups when the customer is offline, you'll need
       to supply their last-known IP directly:
         $0 <ip-address> [hours-back]
EOF
        exit 1
    fi

    CLIENT_LABEL="$INPUT → $CLIENT_IP"
fi

# ============================================================================
# QUERY THE LOG
# ============================================================================
SINCE="${HOURS}h"

echo "==> DNS history for $CLIENT_LABEL (last ${HOURS}h)" >&2
echo "==> Reading from container: $CONTAINER" >&2
echo >&2

# dnsmasq log line format (when log-queries is on):
#   <timestamp> dnsmasq[pid]: <id> <client-ip>/<port> query[A] <name> from <upstream>
#   <timestamp> dnsmasq[pid]: <id> <client-ip>/<port> reply <name> is <answer>
#
# We grep for 'query[' lines from our client, then extract timestamp + name.
docker logs --since "$SINCE" --timestamps "$CONTAINER" 2>&1 \
    | grep -F "${CLIENT_IP}/" \
    | grep -E 'query\[[A-Z]+\]' \
    | awk '{
        # docker --timestamps prepends an ISO ts as field 1; the dnsmasq
        # query line has the queried name as the field after "query[X]".
        ts = $1
        for (i=1; i<=NF; i++) {
            if ($i ~ /^query\[/) {
                qtype = $i
                qname = $(i+1)
                printf "%s  %s  %-7s  %s\n", ts, "'"$CLIENT_IP"'", qtype, qname
                next
            }
        }
    }' \
    | sort -u

# Count summary on stderr so it's separable from the data on stdout
TOTAL=$(docker logs --since "$SINCE" "$CONTAINER" 2>&1 \
    | grep -cF "${CLIENT_IP}/" || true)
echo >&2
echo "==> ~${TOTAL} matching log entries scanned" >&2
