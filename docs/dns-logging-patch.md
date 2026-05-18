# NetPulse Customer DNS Logging Patch

**Goal:** log every domain each PPPoE customer resolves, with timestamp +
source IP, so support staff and BTRC audits can answer "what was this
customer doing on X day at Y time" — to the extent that DNS-level
visibility can.

**Scope of what gets logged:** hostnames only (e.g. `facebook.com`,
`youtube.com`) — NOT full URLs, NOT page content, NOT search queries.
For HTTPS traffic, the hostname is all an ISP can see without breaking
encryption. That's enough for typical compliance + abuse investigation
and not enough for anything more invasive.

**Retention:** 7 days, enforced by docker's `json-file` log rotation
(100MB × 7 files ≈ 700MB ceiling).

> **⚠ BTRC reality check.** BTRC license terms typically demand 6–12
> months of session logs available on demand. 7 days satisfies internal
> debugging but will NOT pass an actual BTRC audit. If/when you need
> long-term retention, swap the `json-file` driver for an external
> store (Loki / Elastic / ClickHouse) and bump the cap. The dnsmasq
> output format is stable — only the storage layer changes.

---

## Architecture

```
[Customer]                  [Mikrotik RB4011]              [VPS 10.99.0.1]
   │                              │                                │
   │  DNS udp/53 to 8.8.8.8 ────► │ NAT rule "NetPulse-dns-log-*"  │
   │                              │ (matches src=PPPoE pool)       │
   │                              │ ──── DNAT to 10.99.0.1:5454 ──►│
   │                              │                                ▼
   │                              │                       dnsmasq-resolver
   │                              │                       ├─ logs query
   │                              │                       ├─ forwards to
   │                              │                       │   1.1.1.1/8.8.8.8
   │                              │                       └─ caches answer
   │                              │                                │
   │ ◄──────── DNS reply ─────────┤ ◄──────────── reply ───────────┤
```

Expired customers continue to hit the **walled-garden sinkhole on port
53** (unchanged from the previous patch) because their NAT rule is
ordered above this one.

---

## Files in this patch

```
docs/dns-logging/
├── mikrotik.rsc                              ← Mikrotik NAT rules
├── dnsmasq-resolver/
│   ├── Dockerfile                            ← alpine + dnsmasq 2.90 (reuses walled-garden one)
│   └── dnsmasq.conf                          ← recursive resolver + log-queries
├── compose-additions.yml                     ← service to splice into compose
└── scripts/
    └── lookup-dns.sh                         ← CLI: customer → query history
```

---

## Prerequisites

1. The walled-garden patch (`walled-garden-patch.md`) is already deployed.
   The existing `dnsmasq` (sinkhole) on port 53 must stay running — this
   patch adds a SECOND dnsmasq instance on port 5454, it does not replace
   the first.
2. You know your PPPoE customer pool subnet(s). Find them on the router:
   ```
   /ip pool print
   ```
   You'll edit `mikrotik.rsc` to replace `<CUSTOMER-POOL-CIDR>` with
   your real CIDR (e.g. `172.16.16.0/24`).

---

## Deploy order

### Step 0 — pre-flight

```bash
ssh root@103.187.22.131

# Confirm the walled-garden dnsmasq is healthy
docker ps --filter name=dnsmasq --format '{{.Names}} {{.Status}}'
# Expect: dnsmasq   Up (healthy)

# Confirm port 5454 is free
ss -lunp | grep 5454
# Expect: (nothing) — port is unused

# Snapshot compose
cp /opt/phpnuxbill/docker-compose.yml /opt/phpnuxbill/docker-compose.yml.bak.$(date +%F-dnslog)
```

### Step 1 — copy files to the VPS

From your local repo:

```bash
rsync -avz docs/dns-logging/dnsmasq-resolver/  root@103.187.22.131:/opt/phpnuxbill/dnsmasq-resolver/
rsync -avz docs/dns-logging/scripts/           root@103.187.22.131:/opt/phpnuxbill/scripts/
ssh root@103.187.22.131 "chmod +x /opt/phpnuxbill/scripts/lookup-dns.sh"
```

### Step 2 — splice resolver into docker-compose.yml

On the VPS, edit `/opt/phpnuxbill/docker-compose.yml` — paste the body
of `dns-logging/compose-additions.yml` under `services:` (2-space indent
matching the existing services).

```bash
cd /opt/phpnuxbill
docker compose build dnsmasq-resolver
docker compose up -d dnsmasq-resolver

# Verify it's up and bound to 5454
docker ps --filter name=dnsmasq-resolver --format '{{.Names}} {{.Status}}'
ss -lunp | grep 5454                              # expect: 10.99.0.1:5454

# Functional test (from the VPS itself)
dig +short -p 5454 example.com @10.99.0.1         # should return a real IP
docker logs --tail 5 dnsmasq-resolver              # should show the query
```

If the resolver doesn't answer, check `docker logs dnsmasq-resolver`
for bind errors — usually means port 5454 was already in use, or
10.99.0.1 isn't up on wg0.

### Step 3 — fill in the Mikrotik script with your real CIDR

On your local machine, edit `docs/dns-logging/mikrotik.rsc` and replace
both `<CUSTOMER-POOL-CIDR>` placeholders with your real pool subnet
(e.g. `172.16.16.0/24`). Save.

### Step 4 — push the Mikrotik script

```bash
scp docs/dns-logging/mikrotik.rsc admin@10.99.0.2:/
ssh admin@10.99.0.2 "/import mikrotik.rsc"
```

Then verify rule ordering — the existing expired-users rules must
remain ABOVE the new dns-log rules. From the Mikrotik terminal:

```
/ip firewall nat print where comment~"NetPulse-(expired-dns|dns-log)"
```

Expected order:
1. `NetPulse-expired-dns-udp` → `10.99.0.1:53`   (sinkhole)
2. `NetPulse-expired-dns-tcp` → `10.99.0.1:53`   (sinkhole)
3. `NetPulse-dns-log-udp`     → `10.99.0.1:5454` (resolver+log)
4. `NetPulse-dns-log-tcp`     → `10.99.0.1:5454` (resolver+log)

If the order is wrong, fix:
```
/ip firewall nat move [find comment="NetPulse-expired-dns-udp"] 0
/ip firewall nat move [find comment="NetPulse-expired-dns-tcp"] 1
```

### Step 5 — end-to-end verification

From a real customer device (or pick one yourself):

```bash
# Trigger a few easily-identifiable queries
nslookup test1-netpulse.example.com
nslookup test2-netpulse.example.com 8.8.8.8     # force a 3rd-party resolver
```

The second one is the proof: even though the client asked Google, the
Mikrotik NAT redirected it to our resolver, which logged it.

On the VPS:

```bash
docker logs --tail 50 dnsmasq-resolver | grep test1-netpulse
docker logs --tail 50 dnsmasq-resolver | grep test2-netpulse
```

Both queries should appear, with the customer's PPPoE IP visible in
the `client-ip/port` field.

---

## Using the lookup script

On the VPS:

```bash
/opt/phpnuxbill/scripts/lookup-dns.sh <customer-username|ip> [hours-back]
```

Examples:
```bash
/opt/phpnuxbill/scripts/lookup-dns.sh shawon@ahad.net           # last 24h
/opt/phpnuxbill/scripts/lookup-dns.sh shawon@ahad.net 6         # last 6h
/opt/phpnuxbill/scripts/lookup-dns.sh 172.16.16.243 48          # by IP, 48h
```

Output (one line per query):
```
2026-05-18T13:42:01Z  172.16.16.243  query[A]    youtube.com
2026-05-18T13:42:01Z  172.16.16.243  query[AAAA] youtube.com
2026-05-18T13:42:03Z  172.16.16.243  query[A]    fonts.googleapis.com
...
```

The script resolves `username → IP` via Mikrotik's CURRENT `/ppp/active`
list. **If the customer is offline, you'll need to pass the IP directly.**
For historical name→IP mapping ("what IP did Shawon have on Tuesday at
3pm"), you'd need PPPoE session-event logging — not in this patch.

---

## Rollback

```bash
# 1. Mikrotik
ssh admin@10.99.0.2
/ip firewall nat remove [find comment="NetPulse-dns-log-udp"]
/ip firewall nat remove [find comment="NetPulse-dns-log-tcp"]

# 2. Container
ssh root@103.187.22.131
cd /opt/phpnuxbill
docker compose stop dnsmasq-resolver && docker compose rm -f dnsmasq-resolver
cp docker-compose.yml.bak.<date>-dnslog docker-compose.yml
# Remove or comment out the dnsmasq-resolver block

# 3. (Optional) drop the on-disk files
rm -rf /opt/phpnuxbill/dnsmasq-resolver/
rm -f  /opt/phpnuxbill/scripts/lookup-dns.sh
```

After rollback, customer DNS reverts to whatever resolver they configured
on their device (typically the router's own IP, or a public one).
Sinkhole for expired users is unchanged throughout.

---

## Known limitations & gotchas

1. **HTTPS hides URLs.** You get `youtube.com` queried, never `/watch?v=xyz`.
   Anyone selling you "full URL logging for HTTPS" is selling you MITM
   with a custom root CA, which is illegal in most jurisdictions and
   ineffective against HSTS-preloaded sites anyway. Don't go there.

2. **DoH/DoT bypass.** A customer with Firefox-DoH enabled or Chrome
   with a custom DoH resolver sends DNS over HTTPS to Cloudflare/Google
   directly — port 443, encrypted, indistinguishable from regular HTTPS.
   You can't see those queries. Mitigation: block known DoH provider IPs
   from customer subnets (separate patch, ask if you want it). Most
   customers don't have DoH enabled by default.

3. **Apps with hardcoded resolvers.** Some apps (especially adblockers
   and privacy tools) ship their own resolver and ignore system DNS.
   Same bypass story as DoH.

4. **DNS ≠ traffic.** A customer who queried `facebook.com` may not have
   actually visited it — the OS may have prefetched, an iframe may have
   loaded, or a tracker may have phoned home. DNS logs prove
   "resolved", not "visited". Be careful drawing conclusions in
   investigations.

5. **7-day retention won't satisfy BTRC.** Repeating from the top: this
   patch is sized for internal debugging. BTRC audits typically need
   6–12 months. When that becomes a requirement, swap the `json-file`
   driver for proper log shipping.

6. **Privacy notice.** Customer ToS must explicitly disclose that
   DNS-level traffic is logged for compliance. If it doesn't, update
   the ToS BEFORE turning this on. "BTRC requires it" is a defence
   against the customer; it isn't a substitute for disclosure.

7. **Admin UI tab (deferred).** A "Customer → Browsing History" tab in
   the IspBill admin panel would call `lookup-dns.sh` via `shell_exec`
   and render results. Deliberately skipped in this patch:
   `shell_exec` with any user-derived input is a remote-code-execution
   risk surface that needs careful argument escaping + a restricted
   binary path + admin-only ACL. Worth doing properly as a follow-up
   if/when support staff actually need GUI access. For now, SSH to the
   VPS and run the CLI.
