#!/bin/bash
# Run AFTER approved DNS cutover and public port 80 validation on both names.
set -euo pipefail
test -f /etc/herokid/GO_LIVE_APPROVED
mkdir -p /var/www/letsencrypt
# Reconfiguration validates against the staging ACME service before saving renewal options.
certbot reconfigure --cert-name hero-kid.com --webroot --webroot-path /var/www/letsencrypt
certbot renew --cert-name hero-kid.com --dry-run
install -d -m 755 /etc/letsencrypt/renewal-hooks/deploy
printf '#!/bin/sh\nnginx -t && systemctl reload nginx\n' > /etc/letsencrypt/renewal-hooks/deploy/herokid-nginx
chmod 755 /etc/letsencrypt/renewal-hooks/deploy/herokid-nginx
