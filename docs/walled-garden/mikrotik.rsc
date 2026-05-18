# NetPulse walled-garden — Mikrotik RB4011 patch
#
# Purpose: ensure every expired customer who tries to use the internet is
# steered to the notice page, including HTTPS / QUIC traffic.
#
# Strategy:
#   1. Sinkhole DNS  — DNAT 53/udp+tcp from expired-users → 10.99.0.1:53
#   2. Reject 443/tcp (HTTPS)  — fast TCP RST so browsers fail in <1s
#   3. Reject 443/udp (QUIC)   — ICMP admin-prohibited so Chrome/Edge fall back
#
# The existing port-80 DNAT rule (NetPulse-expired-redirect) stays as-is.
#
# IMPORTANT: filter rules must be ordered ABOVE any catch-all "accept forward"
# rule. After running this script, verify with `/ip firewall filter print`
# and use `/ip firewall filter move <id> <to-id>` to position the three
# NetPulse-expired-* rules near the top of the forward chain.
#
# Apply via Webfig → Terminal, or:
#   scp -P 22 mikrotik.rsc admin@<router>:/
#   ssh admin@<router> "/import mikrotik.rsc"

# ============================================================================
# 1. DNS SINKHOLE
# ============================================================================
# UDP 53 → VPS dnsmasq
/ip firewall nat add chain=dstnat protocol=udp dst-port=53 \
    src-address-list=expired-users \
    action=dst-nat to-addresses=10.99.0.1 to-ports=53 \
    comment=NetPulse-expired-dns-udp

# TCP 53 (large responses, DoT-fallback) → VPS dnsmasq
/ip firewall nat add chain=dstnat protocol=tcp dst-port=53 \
    src-address-list=expired-users \
    action=dst-nat to-addresses=10.99.0.1 to-ports=53 \
    comment=NetPulse-expired-dns-tcp

# ============================================================================
# 2. REJECT HTTPS (TCP 443) — fast failure so OS captive-portal probes fire
# ============================================================================
/ip firewall filter add chain=forward protocol=tcp dst-port=443 \
    src-address-list=expired-users \
    action=reject reject-with=tcp-reset \
    comment=NetPulse-expired-block-https

# ============================================================================
# 3. REJECT QUIC (UDP 443) — Chrome/Edge prefer HTTP/3 over QUIC, kill it
# ============================================================================
/ip firewall filter add chain=forward protocol=udp dst-port=443 \
    src-address-list=expired-users \
    action=reject reject-with=icmp-admin-prohibited \
    comment=NetPulse-expired-block-quic

# ============================================================================
# 4. POSITION THE FILTER RULES (manual — RouterOS appends to end by default)
# ============================================================================
# After importing, run these from the terminal to push the two reject rules
# above any existing "accept all forward" or "fasttrack" rules:
#
#   /ip firewall filter print where comment~"NetPulse-expired-block"
#   # note the .id of each (e.g. *1A, *1B)
#   /ip firewall filter move [find comment="NetPulse-expired-block-https"] 0
#   /ip firewall filter move [find comment="NetPulse-expired-block-quic"]  1
#
# Then verify ordering:
#   /ip firewall filter print
#
# The two NetPulse-expired-block rules should appear before any
# `action=fasttrack-connection` or `action=accept` rule that would
# otherwise let port 443 through.

# ============================================================================
# VERIFICATION
# ============================================================================
# From an expired-users client (or simulate by adding your test IP to the list):
#
#   /ip firewall address-list add list=expired-users address=10.99.99.99 \
#       comment=NetPulse-test-only
#
#   curl -sv --max-time 5 https://www.google.com   # should fail fast (TCP RST)
#   curl -sv --max-time 5 http://example.com       # should land on notice page
#   dig +short example.com @1.1.1.1                # should return 10.99.0.1
#
#   /ip firewall address-list remove [find comment="NetPulse-test-only"]

# ============================================================================
# ROLLBACK (removes all four NetPulse-expired-* walled-garden rules; keeps
# the original port-80 redirect)
# ============================================================================
# /ip firewall nat    remove [find comment="NetPulse-expired-dns-udp"]
# /ip firewall nat    remove [find comment="NetPulse-expired-dns-tcp"]
# /ip firewall filter remove [find comment="NetPulse-expired-block-https"]
# /ip firewall filter remove [find comment="NetPulse-expired-block-quic"]
