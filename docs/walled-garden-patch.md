# NetPulse Walled-Garden Patch

**Goal:** ensure every expired customer who tries to use the internet is
steered to the notice page — across HTTP, HTTPS (port 443/TCP), and QUIC
(443/UDP) — and that their phone's OS pops the native "Sign in to Wi-Fi
Network" banner the moment they connect.

**What this changes:** adds DNS sinkholing + HTTPS reject on Mikrotik, a
dnsmasq container on the VPS, and replaces the existing `:8089` nginx
vhost with one that handles captive-portal probes.

**What this CANNOT do:** force the notice page to appear when a user
types `facebook.com`, `youtube.com`, `google.com`, or any other
HSTS-preloaded HTTPS site. Those domains are hardcoded into the browser
as "HTTPS only", so the browser never speaks HTTP to anyone for them and
no captive portal anywhere in the world can intercept them. The OS
captive-portal banner is what saves the UX for those cases — the
customer's phone pops a "Sign in" notification that opens the notice
page directly, no matter what URL they were trying.

---

## Files in this patch

```
docs/walled-garden/
├── mikrotik.rsc                          ← RouterOS commands
├── dnsmasq/
│   ├── Dockerfile                        ← alpine + dnsmasq 2.90
│   └── dnsmasq.conf                      ← sinkhole config
├── router-proxy/
│   └── nginx-walled-garden.conf          ← replaces existing :8089 block
└── compose-additions.yml                 ← service to splice into compose
```

---

## Deploy order

Run these in order. Each step is independently verifiable so you can stop
and check before continuing. **Test against a single test address first**
(see step 0) before flipping the real `expired-users` list.

### Step 0 — pre-flight on the VPS

```bash
ssh root@103.187.22.131

# Confirm 10.99.0.1 is up on wg0
ip -br addr show wg0
# Expect: wg0   UP   10.99.0.1/24

# Confirm nothing else is bound to 10.99.0.1:53
ss -lunp | grep :53
# Expect: 127.0.0.53:53 (systemd-resolved) only — NOT *:53 and NOT 10.99.0.1:53.
# If you see *:53, set DNSStubListener=no in /etc/systemd/resolved.conf,
# uncomment DNS=127.0.0.53, and `systemctl restart systemd-resolved` first.

# Snapshot existing config before any changes
cp /opt/phpnuxbill/router-proxy/nginx.conf /opt/phpnuxbill/router-proxy/nginx.conf.bak.$(date +%F)
cp /opt/phpnuxbill/docker-compose.yml      /opt/phpnuxbill/docker-compose.yml.bak.$(date +%F)
```

### Step 1 — copy patch files into deployment

```bash
# Local (this repo):
./deploy.sh                     # gets the IspBill template + notice.php changes live

# Then upload the walled-garden artifacts manually (they're not auto-deployed):
rsync -avz docs/walled-garden/dnsmasq/         root@103.187.22.131:/opt/phpnuxbill/dnsmasq/
rsync -avz docs/walled-garden/router-proxy/    root@103.187.22.131:/opt/phpnuxbill/router-proxy/
```

### Step 2 — splice dnsmasq into docker-compose.yml

On the VPS, edit `/opt/phpnuxbill/docker-compose.yml` and paste the body
of `docs/walled-garden/compose-additions.yml` under the `services:` key
(any indentation matching the existing services — usually 2 spaces).

```bash
cd /opt/phpnuxbill
docker compose build dnsmasq
docker compose up -d dnsmasq

# Verify
docker ps --filter name=dnsmasq --format '{{.Names}} {{.Status}}'
ss -lunp | grep 10.99.0.1
dig +short example.com @10.99.0.1                # → 10.99.0.1
dig +short use-application-dns.net @10.99.0.1    # → empty (NXDOMAIN — Firefox DoH opt-out)
docker logs --tail 20 dnsmasq
```

### Step 3 — replace the router-proxy :8089 vhost

```bash
cd /opt/phpnuxbill/router-proxy

# Edit nginx.conf — find the existing `server { listen 8089; ... }` block
# and REPLACE it with the contents of router-proxy/nginx-walled-garden.conf
# (or `\i router-proxy/nginx-walled-garden.conf` if you maintain it as a
# separate include).

# Validate syntax
docker exec router-proxy nginx -t

# Reload (zero downtime)
docker exec router-proxy nginx -s reload

# Verify each probe URL returns 302 to /notice
for path in /hotspot-detect.html /generate_204 /connecttest.txt /success.txt /check_network_status.txt /; do
    code=$(curl -s -o /dev/null -w "%{http_code} %{redirect_url}" http://10.99.0.1:8089$path)
    echo "$path  →  $code"
done
# Expect: each one → "302 http://10.99.0.1:8089/notice"

# Verify the notice route itself loads
curl -sI http://10.99.0.1:8089/notice | head -5
# Expect: HTTP/1.1 200 OK, Content-Type: text/html

# Verify the actual notice HTML renders
curl -s http://10.99.0.1:8089/notice | grep -o '<title>.*</title>'
```

### Step 4 — apply Mikrotik rules (the irreversible-feeling part)

**Test the rules against ONE non-customer IP first** before applying
to the real `expired-users` list. The script doesn't change `expired-users`
itself — only adds NAT + filter rules that fire when an IP is on that list.

```bash
# On the VPS, push the script to the router:
scp -P 22 docs/walled-garden/mikrotik.rsc admin@10.99.0.2:/

# SSH in and run it (it imports atomically):
ssh admin@10.99.0.2
/import mikrotik.rsc
# Then manually re-order the two filter rules to the TOP of the forward chain:
/ip firewall filter move [find comment="NetPulse-expired-block-https"] 0
/ip firewall filter move [find comment="NetPulse-expired-block-quic"]  1
/ip firewall filter print where comment~"NetPulse-expired"
```

### Step 5 — end-to-end test with a temporary address

```bash
# On the router, add YOUR current PPPoE IP (or a test client's IP) to the list:
/ip firewall address-list add list=expired-users address=<test-ip> comment=NetPulse-test-only

# On the test client:
curl -sv --max-time 5 https://www.google.com    # should fail FAST with TCP RST
curl -sv --max-time 5 http://example.com        # should 302 to notice
nslookup facebook.com                            # should return 10.99.0.1
ping example.com                                 # should ping 10.99.0.1

# On a phone (toggle WiFi off and on):
# - iOS should pop the CNA banner within 5 seconds
# - Android should show "Sign in to Wi-Fi network" notification
# - Tapping the banner should open the Bengali notice page

# When done testing:
/ip firewall address-list remove [find comment="NetPulse-test-only"]
```

---

## What happens after deploy (per scenario)

| What the customer does | Result |
|---|---|
| Joins WiFi on phone | OS captive-portal banner pops within ~5s, opens notice |
| Types `http://example.com` | 302 → notice page renders |
| Types `https://example.com` (non-HSTS) | Browser shows connection error fast; if they hit "open in HTTP" they see notice |
| Types `https://facebook.com` (HSTS) | Browser shows `ERR_CONNECTION_RESET` or similar. Cannot show notice. OS banner is the fallback. |
| Opens Gmail / YouTube / Instagram app | App shows "no internet" error |
| Uses VPN | If VPN is on UDP 443 → blocked. If on a different port → works (limitation) |
| Uses DoH-enabled Firefox | Sinkhole catches the canary domain → Firefox disables DoH → DNS sinkhole works |
| Uses DoH-enabled Chrome with custom resolver | Cannot block this short of blocking the resolver's IP. Most users don't have this. |

---

## Rollback (full, in reverse order)

```bash
# 1. Mikrotik rules
ssh admin@10.99.0.2
/ip firewall filter remove [find comment="NetPulse-expired-block-https"]
/ip firewall filter remove [find comment="NetPulse-expired-block-quic"]
/ip firewall nat remove [find comment="NetPulse-expired-dns-udp"]
/ip firewall nat remove [find comment="NetPulse-expired-dns-tcp"]

# 2. nginx vhost
ssh root@103.187.22.131
cd /opt/phpnuxbill/router-proxy
cp nginx.conf.bak.<date> nginx.conf
docker exec router-proxy nginx -t && docker exec router-proxy nginx -s reload

# 3. dnsmasq container
cd /opt/phpnuxbill
docker compose stop dnsmasq && docker compose rm -f dnsmasq
# Remove the service block from docker-compose.yml (restore from .bak)
cp docker-compose.yml.bak.<date> docker-compose.yml
```

After rollback, the only thing remaining is the original
`NetPulse-expired-redirect` port-80 DNAT rule, which behaves the same as
it did before this patch was applied (HTTP-only notice for expired users).

---

## Known limitations & gotchas

1. **HSTS-preloaded sites cannot be intercepted.** Documented above. Plan
   your customer-comms accordingly — the notice page will show on phones via
   the OS banner, on non-HSTS HTTP attempts, and on every OS captive-portal
   probe. It will NOT show as the rendered HTML of `facebook.com` etc.

2. **DoH bypass.** A customer running Chrome with `dns.google` or
   `1.1.1.1` set as a custom DoH resolver in browser settings will bypass
   our sinkhole. Mitigation: add the resolver IPs to a separate
   `block-doh-providers` address-list and reject them. Out of scope for
   this patch — add later if it becomes a real problem.

3. **OS captive-portal probe URLs change.** Apple/Google/Microsoft
   occasionally rotate or add new probe domains. If a new OS version
   stops triggering the banner, check
   <https://en.wikipedia.org/wiki/Captive_portal#Detection> for the
   current list and add to the nginx vhost.

4. **The "place-before" manual step.** RouterOS appends new filter rules
   to the bottom of the chain by default. If your existing forward chain
   has an `accept` or `fasttrack` rule above ours, our `reject` never
   fires. The script comments explicitly remind you to move them — don't
   skip that.

5. **Customer satisfaction window.** With 443 blocked, a customer trying
   `https://facebook.com` will see a generic connection error, not your
   notice. They may call support saying "internet is broken" rather than
   "I need to recharge." The SMS notification at expiry
   (`Message::sendExpiredNotification` in `cron.php`) becomes more
   important — make sure that pipeline is healthy.
