# Tiny dnsmasq image for the NetPulse walled-garden DNS sinkhole.
# Pinned to a specific Alpine release so rebuilds are reproducible.

FROM alpine:3.20

RUN apk add --no-cache dnsmasq=~2.90 \
 && addgroup -S -g 1000 dnsmasq 2>/dev/null || true

# Config is mounted from compose; no COPY here so we don't need a rebuild
# every time the config changes.

# -k = run in foreground (so docker can supervise)
# -C = use this config file (everything in /etc/dnsmasq.conf, bind-mounted)
# --log-facility=- = log to stdout (docker logs picks it up)
EXPOSE 53/udp 53/tcp
ENTRYPOINT ["dnsmasq", "-k", "-C", "/etc/dnsmasq.conf", "--log-facility=-"]
