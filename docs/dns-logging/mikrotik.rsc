# NetPulse customer DNS logging — Mikrotik patch
#
# Phase 2 of the walled-garden DNS infrastructure: forces ALL PPPoE
# customer DNS through the VPS-side logging resolver (port 5454) so we
# can log every domain each customer looked up (BTRC retention).
#
# Plays nicely with the existing walled-garden expired-users sinkhole
# (still on port 53). Mikrotik NAT processes top-to-bottom and stops on
# first match, so as long as the existing NetPulse-expired-dns-* rule
# is ABOVE this one, expired users still hit the sinkhole first.
#
# ============================================================================
# BEFORE RUNNING — fill in your real customer subnet
# ============================================================================
#
# This file references <CUSTOMER-POOL-CIDR> in two places. Replace it with
# the IP range your PPPoE customers actually get from. Find them with:
#
#     /ip pool print
#
# If you have ONE pool, use its range as the CIDR. Example:
#     pool "50Mbps" with ranges=172.16.16.2-172.16.16.254
#       → use src-address=172.16.16.0/24
#
# If you have MULTIPLE pools (likely — one per tier), either:
#   (a) duplicate the two rules below per subnet, or
#   (b) create a single Mikrotik address-list covering all pools and
#       reference it as src-address-list instead of src-address.
#
# ============================================================================
# FORCE ALL CUSTOMER DNS THROUGH THE LOGGING RESOLVER
# ============================================================================

/ip firewall nat add chain=dstnat protocol=udp dst-port=53 \
    src-address=<CUSTOMER-POOL-CIDR> \
    action=dst-nat to-addresses=10.99.0.1 to-ports=5454 \
    comment=NetPulse-dns-log-udp

/ip firewall nat add chain=dstnat protocol=tcp dst-port=53 \
    src-address=<CUSTOMER-POOL-CIDR> \
    action=dst-nat to-addresses=10.99.0.1 to-ports=5454 \
    comment=NetPulse-dns-log-tcp

# ============================================================================
# ENFORCE RULE ORDERING — expired-users sinkhole must run BEFORE these
# ============================================================================
#
# After import, verify the four NetPulse DNS rules with:
#
#     /ip firewall nat print where comment~"NetPulse-(expired-dns|dns-log)"
#
# Expected order (top → bottom):
#     1. NetPulse-expired-dns-udp        → 10.99.0.1:53     (sinkhole)
#     2. NetPulse-expired-dns-tcp        → 10.99.0.1:53     (sinkhole)
#     3. NetPulse-dns-log-udp            → 10.99.0.1:5454   (resolver+log)
#     4. NetPulse-dns-log-tcp            → 10.99.0.1:5454   (resolver+log)
#
# If the new dns-log rules ended up ABOVE the expired-dns rules, fix:
#
#     /ip firewall nat move [find comment="NetPulse-expired-dns-udp"] 0
#     /ip firewall nat move [find comment="NetPulse-expired-dns-tcp"] 1

# ============================================================================
# VERIFICATION (run from an active PPPoE customer's network)
# ============================================================================
#
#     dig example.com                           # answer should still be correct
#     dig facebook.com @8.8.8.8                 # forced through us anyway — same IP
#
# Then on the VPS:
#
#     docker logs --tail 20 dnsmasq-resolver    # see your test query logged
#
# ============================================================================
# ROLLBACK (removes only the DNS-log rules; sinkhole untouched)
# ============================================================================
#
# /ip firewall nat remove [find comment="NetPulse-dns-log-udp"]
# /ip firewall nat remove [find comment="NetPulse-dns-log-tcp"]
