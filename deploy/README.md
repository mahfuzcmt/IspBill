# Deployment Guide

## 1. Clean Up Duplicate PPPoE Plans

Run this command on the server:

```bash
docker exec phpnuxbill php /var/www/html/system/cleanup-pppoe.php
```

This will:
- Remove PPPoE plans with router "1" (duplicates)
- Remove 100Mbps, 150Mbps, 200Mbps plans
- Keep: 50Mbps, 75Mbps, 90Mbps, Suspended, Unlimited

---

## 2. Deploy Due Warning System

### Step 1: Copy files to server
Copy these files to /opt/phpnuxbill/overrides on the server:
- `system/controllers/due-notice.php`
- `system/due-sync.php`

### Step 2: Update nginx config
Add this to `/opt/phpnuxbill/router-proxy/nginx.conf`:

```nginx
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
```

### Step 3: Restart nginx
```bash
docker restart router-proxy
```

### Step 4: Set up cron job
Add to crontab (`crontab -e`):
```
*/5 * * * * docker exec phpnuxbill php /var/www/html/system/due-sync.php >> /var/log/due-sync.log 2>&1
```

### Step 5: Mikrotik NAT rule (already configured)
The NAT rule should already exist:
```
/ip firewall nat add chain=dstnat src-address-list=due-warning protocol=tcp dst-port=80 action=dst-nat to-addresses=10.99.0.1 to-ports=8089 comment="Redirect due users to notice page"
```

---

## How Due Warning Works

1. **Cron job (every 5 min)**: `due-sync.php` finds customers with negative balance and their active PPPoE session IPs
2. **Address list update**: Adds those IPs to "due-warning" address list on Mikrotik (5 min timeout)
3. **NAT redirect**: When user browses HTTP, Mikrotik redirects to port 8089
4. **Notice page**: Shows Bangla payment notice with bKash/Nagad/Rocket numbers
5. **Auto-redirect**: After 30 seconds, redirects to user's original URL
6. **Daily limit**: Only shows 4 times per day per user (tracked via temp file)
