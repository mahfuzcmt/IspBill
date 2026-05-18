# Identical to the walled-garden sinkhole Dockerfile —
# the difference is the config bind-mounted at runtime, not the binary.
# Kept here so this patch is self-contained; if you already deployed the
# walled-garden patch, you can point this service at ../dnsmasq/Dockerfile
# in compose to avoid the duplicate.

FROM alpine:3.20

RUN apk add --no-cache dnsmasq=~2.90

EXPOSE 53/udp 53/tcp 5454/udp 5454/tcp
ENTRYPOINT ["dnsmasq", "-k", "-C", "/etc/dnsmasq.conf", "--log-facility=-"]
