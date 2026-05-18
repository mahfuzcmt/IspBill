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

**Retention:** 7 days, enforced by host-side `logrotate` (daily, 7
rotations, gzip after day 1, copytruncate so dnsmasq's file handle
stays valid).

**Access:**
- **Admin UI** — IspBill admin panel → Customer → **Browsing History** tab
- **CLI** — `/opt/phpnuxbill/scripts/lookup-dns.sh <username|ip> [hours]`

> **⚠ BTRC reality check.** BTRC license terms typically demand 6–12
> months of session logs available on demand. 7 days satisfies internal
> debugging but will NOT pass an actual BTRC audit. If/when you need
> long-term retention, swap logrotate for proper log shipping
> (Loki/Elastic/ClickHouse) and bump the cap. The dnsmasq log line
> format is stable — only the storage layer changes.

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
   │                              │                       ├─ logs query →
   │                              │                       │   queries.log
   │                              │                       ├─ forwards to
   │                              │                       │   1.1.1.1/8.8.8.8
   │                              │                       └─ caches answer
   │                              │                                │
   │ ◄──────── DNS reply ─────────┤ ◄──────────── reply ───────────┤

   queries.log lives at /opt/phpnuxbill/dnsmasq-resolver/logs/
   bind-mounted INTO BOTH containers:
     - dnsmasq-resolver  rw  (writes the log)
     - phpnuxbill-app    ro  (reads via the Browsing-History tab)
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
│   ├── Dockerfile                            ← alpine + dnsmasq 2.90
│   └── dnsmasq.conf                          ← recursive resolver + log-queries to file
├── compose-additions.yml                     ← resolver service + phpnuxbill-app mount
├── logrotate.conf                            ← daily, keep 7, copytruncate
└── scripts/
    └── lookup-dns.sh                         ← CLI fallback (reads same file)

system/controllers/customers.php              ← new `browsing` case (~120 lines)
ui/ui/customers-browsing.tpl                  ← new admin tab template
ui/ui/customers-edit.tpl                      ← + Browsing History button
ui/ui/customers-diagnose.tpl                  ← + Browsing button
ui/ui/customers-graph.tpl                     ← + Browsing button (consistency)
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

### Step 0 — pre-flight on the VPS

```bash
ssh root@103.187.22.131

# Confirm the walled-garden dnsmasq is healthy
docker ps --filter name=dnsmasq --format '{{.Names}} {{.Status}}'

# Confirm port 5454 is free
ss -lunp | grep 5454                    # expect: (nothing)

# Snapshot compose
cp /opt/phpnuxbill/docker-compose.yml /opt/phpnuxbill/docker-compose.yml.bak.$(date +%F-dnslog)

# Pre-create the log directory with correct permissions
mkdir -p /opt/phpnuxbill/dnsmasq-resolver/logs
touch    /opt/phpnuxbill/dnsmasq-resolver/logs/queries.log
chmod 644 /opt/phpnuxbill/dnsmasq-resolver/logs/queries.log
```

### Step 1 — copy patch files to the VPS

From your local repo:

```bash
# Walled-garden artifacts (resolver + scripts)
rsync -avz docs/dns-logging/dnsmasq-resolver/  root@103.187.22.131:/opt/phpnuxbill/dnsmasq-resolver/
rsync -avz docs/dns-logging/scripts/           root@103.187.22.131:/opt/phpnuxbill/scripts/
ssh root@103.187.22.131 "chmod +x /opt/phpnuxbill/scripts/lookup-dns.sh"

# IspBill app changes (controller + templates) — via the usual deploy.sh
./deploy.sh
```

### Step 2 — install logrotate config

```bash
scp docs/dns-logging/logrotate.conf root@103.187.22.131:/etc/logrotate.d/dnsmasq-resolver
ssh root@103.187.22.131 "
    chmod 644 /etc/logrotate.d/dnsmasq-resolver
    logrotate -d /etc/logrotate.d/dnsmasq-resolver   # dry-run — verify config
"
```

### Step 3 — splice the resolver + phpnuxbill-app mount into docker-compose.yml

On the VPS, edit `/opt/phpnuxbill/docker-compose.yml`:

1. **Add the `dnsmasq-resolver:` service block** under `services:`
   (paste the top half of `dns-logging/compose-additions.yml`).
2. **Add the read-only log volume to the existing `phpnuxbill-app` service**:
   ```yaml
   phpnuxbill-app:
     ...
     volumes:
       - ./overrides/...                                  # existing lines
       - ./dnsmasq-resolver/logs:/var/log/dnsmasq:ro      # ← ADD THIS
   ```

Then bring it all up:

```bash
cd /opt/phpnuxbill
docker compose build dnsmasq-resolver
docker compose up -d dnsmasq-resolver phpnuxbill-app

# Verify both
docker ps --filter name=dnsmasq-resolver --format '{{.Names}} {{.Status}}'
docker ps --filter name=phpnuxbill-app   --format '{{.Names}} {{.Status}}'

# Verify mount inside phpnuxbill-app
docker exec phpnuxbill-app ls -la /var/log/dnsmasq/

# Functional test (from the VPS itself)
dig +short -p 5454 example.com @10.99.0.1            # should return a real IP
sleep 1
tail -3 /opt/phpnuxbill/dnsmasq-resolver/logs/queries.log
```

If `tail` doesn't show the query, check `docker logs dnsmasq-resolver`
for permission errors writing to `/var/log/dnsmasq/queries.log`.

### Step 4 — fill in + push Mikrotik rules

On your local machine, edit `docs/dns-logging/mikrotik.rsc` and replace
both `<CUSTOMER-POOL-CIDR>` placeholders with your real pool subnet
(e.g. `172.16.16.0/24`). Then push and verify ordering exactly as
described in the original commit — the existing expired-users sinkhole
rule must remain ABOVE the new dns-log rules.

### Step 5 — end-to-end verification

**Via the admin UI:**

1. Open `http://103.187.22.131:8081/index.php?_route=customers/list`
2. Click any active customer with `Edit`
3. Click **Browsing History** button at the bottom of the form (or the
   `Browsing` tab from the Diagnose / Graph page)
4. You should see their queries from the last 24 hours, with controls
   to change the window (1h/6h/24h/48h/7d), filter by domain substring,
   or override the IP if the customer is offline.

**Via the CLI:**

```bash
/opt/phpnuxbill/scripts/lookup-dns.sh shawon@ahad.net 6
```

Both surfaces read the same `queries.log` file — they will show
identical results.

### Step 6 — confirm logrotate runs tomorrow

```bash
# Force a rotation right now (verifies the config works without waiting)
logrotate -f /etc/logrotate.d/dnsmasq-resolver
ls -la /opt/phpnuxbill/dnsmasq-resolver/logs/
# Expect: queries.log (live), queries.log.1 (just rotated), no errors
```

---

## Using the Browsing History tab

```
┌─────────────────────────────────────────────────────────────────────┐
│ Browsing History — shawon@ahad.net          [Edit] [Diagnose] [Graph]│
├─────────────────────────────────────────────────────────────────────┤
│ Shawon Ahmed · 01911982568                                          │
│ DNS queries observed by our recursive resolver. Hostnames only.     │
│                                                                     │
│ ┌─ Controls ─────────────────────────────────────────────────┐      │
│ │ Window: [Last 24 hours ▼]  Filter: [____]  IP: [____]      │      │
│ │ Querying for IP: 172.16.16.243 (live PPPoE session)        │      │
│ └────────────────────────────────────────────────────────────┘      │
│                                                                     │
│ Top 12 domains in this window                                       │
│ [youtube.com ×142] [fonts.googleapis.com ×98] [google.com ×64] ...  │
│                                                                     │
│ 1240 queries                                                        │
│ ┌────────────────────┬──────┬──────────────────────────────┐        │
│ │ 2026-05-18 14:30:01│ A    │ youtube.com                  │        │
│ │ 2026-05-18 14:30:01│ AAAA │ youtube.com                  │        │
│ │ 2026-05-18 14:30:03│ A    │ fonts.googleapis.com         │        │
│ │ ...                                                       │        │
│                                                                     │
│ DNS query logging is enabled under BTRC license requirements.       │
└─────────────────────────────────────────────────────────────────────┘
```

- **Click a domain chip** to filter the table to just that domain
- **Switch the window** to 1h / 6h / 24h / 48h / 7d
- **Type into "Filter domain"** for substring matches (e.g. `face` → all `facebook.com`, `facebook.net`, `facebookgaming.com`)
- **IP override** — if the customer is offline, paste their last-known IP

The UI is `_admin()`-gated — Admin and Sales user types only.

---

## Rollback

```bash
# 1. Mikrotik
ssh admin@10.99.0.2
/ip firewall nat remove [find comment="NetPulse-dns-log-udp"]
/ip firewall nat remove [find comment="NetPulse-dns-log-tcp"]

# 2. Containers
ssh root@103.187.22.131
cd /opt/phpnuxbill
docker compose stop dnsmasq-resolver && docker compose rm -f dnsmasq-resolver

# Restore previous compose (removes dnsmasq-resolver service AND the
# phpnuxbill-app log-mount line)
cp docker-compose.yml.bak.<date>-dnslog docker-compose.yml
docker compose up -d phpnuxbill-app    # recreate without the log mount

# 3. Logrotate
rm /etc/logrotate.d/dnsmasq-resolver

# 4. IspBill app changes — revert the commit
cd /path/to/IspBill-repo
git revert <commit-hash>
./deploy.sh
```

After rollback, the **walled-garden sinkhole on port 53 is untouched**.

---

## Known limitations & gotchas

1. **HTTPS hides URLs.** You get `youtube.com` queried, never `/watch?v=xyz`.
   Anyone selling "full URL logging for HTTPS" is selling MITM with a
   custom root CA, which is illegal in most jurisdictions and ineffective
   against HSTS-preloaded sites anyway. Don't go there.

2. **DoH/DoT bypass.** A customer with Firefox-DoH enabled or Chrome
   with a custom DoH resolver sends DNS over HTTPS to Cloudflare/Google
   directly — port 443, encrypted, indistinguishable from regular HTTPS.
   You can't see those queries. Mitigation: block known DoH provider
   IPs from customer subnets (separate patch). Most customers don't
   have DoH enabled by default.

3. **Apps with hardcoded resolvers.** Some apps (especially adblockers
   and privacy tools) ship their own resolver and ignore system DNS.
   Same bypass story as DoH.

4. **DNS ≠ traffic.** A customer who queried `facebook.com` may not
   have actually visited it — the OS may have prefetched, an iframe
   may have loaded, or a tracker may have phoned home. DNS logs prove
   "resolved", not "visited". Be careful drawing conclusions in
   investigations.

5. **Historical username → IP mapping is not implemented.** The
   Browsing tab uses the customer's CURRENT `/ppp/active` IP. If the
   customer was online with a different IP yesterday, you have to
   know that IP and paste it into the IP override box. A future patch
   could log PPPoE up/down events to a small SQLite table so this
   becomes automatic.

6. **7-day retention won't satisfy BTRC.** Repeating from the top: this
   patch is sized for internal debugging. BTRC audits typically need
   6–12 months. When that becomes a requirement, swap logrotate for
   proper log shipping.

7. **Privacy notice.** Customer ToS must explicitly disclose that
   DNS-level traffic is logged for compliance. "BTRC requires it" is
   a defence against the customer; it isn't a substitute for
   disclosure. The Browsing History tab includes a legal footer noting
   the logging — keep it visible in any screenshot/export you share.

8. **Performance.** The Browsing tab streams the log file line-by-line
   in PHP with substring pre-filtering. A 7-day window on a busy
   instance might take 2–5 seconds. If that becomes painful, the
   next step is a small indexer that maintains an SQLite of
   (ip, ts, qname) entries written from a sidecar tailing the log —
   not done in this patch to keep the surface small.
