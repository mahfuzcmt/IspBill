# NetPulse / IspBill image.
#
# Layered on top of the upstream PHPNuxBill image so we keep Apache, PHP, and
# the third-party autoload artifacts as-is, then drop our customised source
# (controllers, templates, traffic-poller, config.php) over /var/www/html.
#
# Build context is the project root (../). docker-compose handles that.
FROM animegasan/phpnuxbill:latest

# Copy the entire working tree over the image's webroot.
# .dockerignore strips .git, .deploy, IDE noise, and other non-runtime files.
COPY --chown=www-data:www-data . /var/www/html/

# Preserve the upstream uploads volume mount point.
RUN mkdir -p /var/www/html/system/uploads \
 && chown -R www-data:www-data /var/www/html/system/uploads
