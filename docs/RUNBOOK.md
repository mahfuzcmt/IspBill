# NetPulse — Operational Runbook

This file captures the live state of the deployment so anyone (you, future-you, a teammate) can pick up where the last session left off. **No credentials are in this file** — they live in your password manager. Placeholders are marked `<SECRET>`.

Last updated: 2026-05-15.

---

## 1. Architecture at a glance

```
                          INTERNET
                              │
                              ▼
                    [ AWS VPS (Mumbai) ]   103.187.22.131
                    │                  │
        ┌───────────┴──┐   ┌───────────┴────────────┐
        │ NetPulse app │   │ router-proxy nginx     │
        │ (Docker)     │   │ (Docker, host net)     │
        │ :8081        │   │ :8090 (Webfig main)    │
        │              │   │ :8089 (notice page)    │
        └─────┬────────┘   └──┬─────────────────────┘
              │               │
              ▼               ▼
         [ MariaDB ]      WireGuard wg0  10.99.0.1
         (Docker)              │
                               ▼  10.99.0.2
                    ┌────────────────────────┐
                    │ Main RB4011 (RouterOS  │
                    │ 7.19.6) @ 10.99.0.2    │
                    │ ─ PPPoE termination    │
                    │ ─ Hotspot bridge       │
                    └─────┬──────────────────┘
                          │ ether7-PPPoE
                          ▼
                ┌──────────────────────┐
                │ Secondary hEX lite   │  RouterOS 6.49.8
                │ (PPPoE-client uplink │  PPPoE caller-id 48:8F:5A:21:0A:14
                │  named "hotspot")    │  reachable via main as 172.16.16.254
                │ ether3 → OLT PON     │
                │ ether4 → OLT MGMT    │  ⚠ currently DOWN (cable unplugged)
                │ ether5 → 10.110.0.1  │  hotspot WiFi LAN
                └──────────┬───────────┘
                           ▼  ether3
                    ┌──────────────┐
                    │ Media OLT    │  brand "Media" — model TBD
                    │ + PON fanout │
                    └──────┬───────┘
                           ▼  GPON ODN
                      Customer ONUs
                      (PPPoE clients)
```

## 2. VPS / containers

VPS: `103.187.22.131` (Ubuntu 24.04, Mumbai region). SSH as `root`.

```
$ docker ps  --format 'table {{.Names}}\t{{.Image}}\t{{.Ports}}'
NAMES               IMAGE                          PORTS
phpnuxbill-app      animegasan/phpnuxbill:latest   0.0.0.0:8081->80/tcp
phpnuxbill-db       mariadb:10.11                  internal only
router-proxy        nginx:1.27-alpine              host net (8090, 8089)
kosaibari-*         (unrelated other project)      .
pingpath-*          (unrelated other project)      .
dropandgo-*         (unrelated other project)      .
```

Project tree on the VPS:

```
/opt/phpnuxbill/
├── docker-compose.yml         # services: nuxbill-db, nuxbill-app, router-proxy
├── .env                       # DB_ROOT_PASSWORD, DB_PASSWORD (live secrets)
├── app-data/                  # uploads, bind-mounted into /var/www/html/system/uploads
├── db-data/                   # MariaDB datadir
├── backups/                   # mysqldumps from every migration this session
├── router-proxy/              # nginx config + htpasswd for the proxy container
│   └── nginx.conf
└── overrides/                 # OUR source overrides bind-mounted into phpnuxbill-app
    ├── index.php
    ├── config.php
    ├── system/
    │   ├── cron.php
    │   ├── traffic-poller.php
    │   ├── controllers/
    │   │   ├── customers.php
    │   │   ├── prepaid.php
    │   │   ├── notice.php
    │   │   └── sms.php
    │   ├── autoload/
    │   │   ├── Mikrotik.php
    │   │   └── SmsSender.php
    │   └── lan/english/common.lan.php
    └── ui/ui/
        ├── *.tpl  (our customised templates)
        ├── scripts/vendor/   (Chart.js + luxon + adapter)
        └── ...
```

Everything under `overrides/` mirrors the same path under `/var/www/html/` inside the `phpnuxbill-app` container via read-only bind mounts in `docker-compose.yml`. So editing `overrides/system/controllers/customers.php` on the VPS instantly affects the live app.

## 3. Access

| Layer | URL / endpoint | Credential |
|---|---|---|
| Admin panel | `http://103.187.22.131:8081/` (auto-redirects to admin) | admin / `<APP_ADMIN_PW>` |
| Main Mikrotik Webfig (public) | `http://103.187.22.131:8090/` | admin / `<MT_ADMIN_PW>` |
| Main Mikrotik API (internal) | `10.99.0.2:8728` from inside WG | admin / `<MT_ADMIN_PW>` |
| Secondary Mikrotik Webfig | `http://10.99.0.2:18080/` (WG-side only) | admin / `<MT2_ADMIN_PW>` |
| Secondary Winbox / SSH / API | `10.99.0.2:18291` / `:18022` / `:18728` | admin / `<MT2_ADMIN_PW>` |
| Expired-customer notice | `http://103.187.22.131:8081/index.php?_route=notice/<username>` | public |
| MariaDB | inside Docker network as `nuxbill-db:3306` | phpnuxbill / `<DB_PW>` (also root with `<DB_ROOT_PW>`) |
| WireGuard server | UDP 51820 on VPS | preshared peer key (in `wg0` config) |

Credentials are kept in your password manager. **Never paste them in chat or commit them.**

## 4. Mikrotik state changes we made (Main RB4011)

### `tbl_routers` row
```sql
UPDATE tbl_routers SET ip_address='10.99.0.2', username='admin', password='<MT_ADMIN_PW>' WHERE id=1;
```

### Suspended PPP profile
```
/ppp profile add name=Suspended rate-limit=256k/256k address-list=expired-users \
    comment="NetPulse: expired/suspended subscribers — HTTP redirected to notice page"
```

### Firewall NAT rules (added by API, comment-tagged)

> **Walled-garden (HTTPS coverage):** the rules below are the *original*
> port-80-only redirect. A full walled-garden patch that also handles
> HTTPS / QUIC / OS captive-portal probes lives in
> [`walled-garden-patch.md`](./walled-garden-patch.md). When deployed,
> it adds DNS sinkholing + 443 reject rules alongside the rules below
> (the port-80 redirect is untouched). Roll back with the commands at
> the bottom of that doc.
>
> **Customer DNS logging (BTRC):** a follow-on patch logs every PPPoE
> customer's DNS queries to a 7-day-rotated docker log via a second
> dnsmasq instance on `10.99.0.1:5454`. See
> [`dns-logging-patch.md`](./dns-logging-patch.md) for deploy/rollback
> and the `lookup-dns.sh` query CLI. Does NOT capture full URLs or
> HTTPS payloads — DNS-level visibility only.

```
# Forward HTTP from expired-users address-list to the notice proxy on the VPS
/ip firewall nat add chain=dstnat protocol=tcp dst-port=80 \
    src-address-list=expired-users \
    action=dst-nat to-addresses=10.99.0.1 to-ports=8089 \
    comment=NetPulse-expired-redirect

# Forward four ports from WireGuard to the secondary Mikrotik for management
/ip firewall nat add chain=dstnat protocol=tcp dst-address=10.99.0.2 \
    in-interface=wg-vps dst-port=18080 \
    action=dst-nat to-addresses=172.16.16.254 to-ports=80 \
    comment=NetPulse-fwd-to-secondary-http-webfig
# …same pattern for 18291→8291 (winbox), 18728→8728 (api), 18022→22 (ssh)
```

### Hardening
```
/interface ovpn-client set [find name=SmartISP_Remote] disabled=yes
/ip service set [find name=telnet] disabled=yes
```

### Hotspot profile pointed at the rebranded theme
```
/ip hotspot profile set [find name=hsprof1] html-directory=tnr-hotspot-login-v14-en-bn
```

The HTML files under `tnr-hotspot-login-v14-en-bn/` on the Mikrotik were rebranded TNR→NetPulse in-place (FTP upload). 19 files, 25→0 TNR mentions remaining.

### Bandwidth / queue stats
The cron `traffic-poller.php` polls `/queue/simple/print stats=yes` every minute and writes one row per active PPPoE session to `tbl_traffic_samples`. 7-day retention.

## 5. Database state changes (live `phpnuxbill` DB)

### Schema
```sql
CREATE TABLE tbl_traffic_samples (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    username VARCHAR(64) NOT NULL,
    rate_in BIGINT UNSIGNED NOT NULL DEFAULT 0,
    rate_out BIGINT UNSIGNED NOT NULL DEFAULT 0,
    bytes_in BIGINT UNSIGNED NOT NULL DEFAULT 0,
    bytes_out BIGINT UNSIGNED NOT NULL DEFAULT 0,
    INDEX idx_user_ts (username, ts),
    INDEX idx_ts (ts)
) ENGINE=InnoDB;
```

### `tbl_appconfig` rows seeded
```sql
INSERT IGNORE INTO tbl_appconfig (setting, value) VALUES
    ('router_web_url',   'http://103.187.22.131:8090/'),
    ('sms_enabled',      '1'),
    ('sms_api_url',      'https://bulksmsbd.net/api/smsapi'),
    ('sms_api_key',      '<SMS_API_KEY>'),
    ('sms_sender_id',    '<SMS_SENDER_ID>'),
    ('sms_template_welcome',  '…'),
    ('sms_template_recharge', '…'),
    ('sms_template_expiry',   '…'),
    ('sms_template_voucher',  '…');
UPDATE tbl_appconfig SET value='NetPulse' WHERE setting='CompanyName';
```

### Plans added during the session
- `tbl_bandwidth`: 50Mbps, 5Mbps, 20Mbps (10Mbps was pre-existing)
- `tbl_plans`: 50Mbps (PPPoE 500৳/30d), 5Mbps_1Day, 5Mbps_7Day, 10Mbps_1Day, 10Mbps_7Day, 20Mbps_1Day, 20Mbps_30Day (all Hotspot)
- `tbl_pool`: 50Mbps → 172.16.16.2-172.16.16.254

### Customers + recharges
30 customers loaded from the SmartISP CSV. Real names/phones in DB. Plans + recharges synced to Mikrotik PPP secrets.

## 6. Cron jobs (on the VPS, `crontab -l` as root)

```
# PHPNuxBill — auto-expire recharges + suspend PPPoE on Mikrotik
0 * * * * docker exec phpnuxbill-app sh -c "cd /var/www/html/system && php cron.php" \
    >> /var/log/phpnuxbill-cron.log 2>&1

# PHPNuxBill traffic poller — writes /queue/simple stats to tbl_traffic_samples
* * * * * docker exec phpnuxbill-app php /var/www/html/system/traffic-poller.php \
    >> /var/log/phpnuxbill-traffic.log 2>&1
```

## 7. OLT — current blocker

**Brand:** "Media" (vendor uncertain; likely a Chinese ODM rebrand — V-Sol / BDCOM / FiberHome / GPON-OLT-IC). Need physical inspection.

**Wiring (as of last probe):**
- OLT **PON data uplink** → cable → **secondary Mikrotik ether3** ✅ working (customer PPPoE flows through)
- OLT **management Ethernet** → cable → **secondary Mikrotik ether4** ❌ **DOWN** — link is dead, possibly cable unplugged or OLT mgmt port faulty.

`192.168.1.0/24` is the configured OLT management subnet; the secondary holds `192.168.1.1/24` on ether4 waiting for the OLT to reappear.

**To unblock (do this physically at the OLT site):**
1. Find the cable that runs from the OLT's MGMT/ETH0 port to the secondary Mikrotik.
2. If unplugged, plug it back into **ether4** on the hEX lite.
3. Confirm link LED on both ends.
4. From your dev environment, run a quick re-probe (see §9).

Once the link is up, the OLT typically gets DHCP from the Mikrotik (or has a static IP in `192.168.1.0/24`). Pick that up from `/ip/arp/print` on the secondary and we can SNMP / SSH it.

## 8. Local development quickstart

Your local fork lives at `C:\xampp\htdocs\phpnuxbill\`. Repo: `https://github.com/mahfuzcmt/IspBill.git`.

```bash
git clone https://github.com/mahfuzcmt/IspBill.git
cd IspBill
cp config.php.example config.php          # fill in DB creds for your local XAMPP
# Or run via docker compose using the .deploy/docker-compose.yml as a starting point.
```

### Running the app locally with XAMPP
1. Symlink or copy this directory into `C:\xampp\htdocs\`.
2. Create a MySQL database `phpnuxbill`, import any of the dumps in `/opt/phpnuxbill/backups/` (pull one down via `scp root@vps:/opt/phpnuxbill/backups/phpnuxbill-pre-csv2-*.sql.gz .`).
3. Edit `config.php` to point at XAMPP's MySQL: `$db_host='127.0.0.1'; $db_user='root'; $db_password='';`.
4. Browse `http://localhost/phpnuxbill/`.

### Running the app locally via Docker (mirrors the VPS)
Use `.deploy/docker-compose.yml` (snapshot of the live compose) as a template. You'll need to bring up MariaDB + the `animegasan/phpnuxbill:latest` image with all the override mounts pointing at your local checkout.

## 9. Deploy workflow (how changes get to the VPS)

This is what the session has been doing repeatedly. Two parts:

### Push code (PHP/TPL changes)
```powershell
# from local repo, copy to /tmp/, then atomic in-place replace
pscp -batch -pw <root_pw> <local-file> root@103.187.22.131:/tmp/file.ext
plink -batch -ssh -P 22 -l root -pw <root_pw> 103.187.22.131 \
    'cat /tmp/file.ext > /opt/phpnuxbill/overrides/<path>/file.ext && \
     docker exec phpnuxbill-app sh -c "find /var/www/html/ui/compiled -maxdepth 1 -name *.tpl.php -delete"'
```

Use `cat > target` (not `mv`) to **preserve the bind-mount inode** — `mv` changes the inode and Docker won't see the new content until a container restart.

### Push Mikrotik config changes
Use the API. Existing example scripts in this session show the pattern: connect via `PEAR2\Net\RouterOS`, send `Request` objects. Comment-tag rules with `NetPulse-*` so they're searchable.

### Add a new bind mount
1. Add the override file to `overrides/...` on the VPS.
2. Edit `/opt/phpnuxbill/docker-compose.yml`, add a `:ro` volume line.
3. `docker compose up -d --no-deps nuxbill-app` to recreate the container.
4. **Watch out**: the container will lose its `config.php` if you forget to mount that one (was a real bug in this session).

## 10. Quick reference — what we built

| URL | Purpose |
|---|---|
| `/` | Redirects to admin login |
| `/index.php?_route=admin` | Admin login |
| `/index.php?_route=customers/list` | Customer list w/ live router status |
| `/index.php?_route=customers/edit/<id>` | Edit + push to Mikrotik + Send SMS |
| `/index.php?_route=customers/billing/<id>` | Edit billing / migrate plan |
| `/index.php?_route=customers/graph/<id>` | Live + 7-day bandwidth graph |
| `/index.php?_route=customers/diagnose/<id>` | Health-check page |
| `/index.php?_route=customers/live-traffic` | All active sessions, real-time rate |
| `/index.php?_route=customers/live-traffic-data` | JSON for the live page |
| `/index.php?_route=sms/settings` | SMS API + 4 templates |
| `/index.php?_route=notice/<username>` | Public expired-customer notice |
| `http://103.187.22.131:8090/` | Main Mikrotik Webfig (proxy) |

## 11. Things to do after the OLT is back online

1. Re-probe the secondary to find the OLT's IP (steps in §9).
2. Add an `OltClient` autoload class (likely SNMP via `snmpget` if PHP-SNMP is available in the container, else shell out via `snmpwalk`).
3. Wire it into `customers/diagnose/<id>` so the page also shows:
   - ONU optical RX power (`pon_onu_optical_diagnose` MIB, varies per vendor)
   - ONU registration state
   - Distance from OLT
   - Recent alarms
4. Threshold colours: <-26 dBm = warn, <-28 dBm = red, ≥-25 dBm = green.
5. Add a hotspot scoreboard / total-bandwidth dashboard widget.
6. Email/SMS expiry-reminder cron (template `sms_template_expiry` already in DB).
