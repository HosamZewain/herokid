#!/bin/bash
# Read-only source; additions/updates only at destination. No --delete.
# Call only after GO-LIVE approval for the FINAL pass. Preparation may use --dry-run.
set -euo pipefail
umask 077
mode=${1:---dry-run}
case "$mode" in --dry-run) flags=(--dry-run);; --apply-after-go-live) flags=();; *) exit 64;; esac
: "${HOSTINGER_SSH_KEY:?Set secure key path}"
: "${AWS_SSH_KEY:?Set secure key path}"
: "${MEDIA_CACHE:?Set protected controller cache path}"
mkdir -p "$MEDIA_CACHE/public" "$MEDIA_CACHE/expenses"
chmod 700 "$MEDIA_CACHE"
# Populate a resumable controller cache first; this never changes Hostinger.
rsync -a --stats -e "ssh -o BatchMode=yes -p 65002 -i $HOSTINGER_SSH_KEY" u470070883@194.55.133.161:/home/u470070883/domains/hero-kid.com/public_html/storage/app/public/ "$MEDIA_CACHE/public/"
rsync -a --stats -e "ssh -o BatchMode=yes -p 65002 -i $HOSTINGER_SSH_KEY" u470070883@194.55.133.161:/home/u470070883/domains/hero-kid.com/public_html/storage/app/private/expenses/ "$MEDIA_CACHE/expenses/"
# Destination requires sudo rsync for its existing ownership; keep expenses out of S3.
rsync -a --stats "${flags[@]}" --rsync-path='sudo rsync' -e "ssh -o BatchMode=yes -i $AWS_SSH_KEY" "$MEDIA_CACHE/public/" ubuntu@63.188.124.6:/var/www/herokid/storage/app/public/
rsync -a --stats "${flags[@]}" --rsync-path='sudo rsync' -e "ssh -o BatchMode=yes -i $AWS_SSH_KEY" "$MEDIA_CACHE/expenses/" ubuntu@63.188.124.6:/var/www/herokid/storage/app/private/expenses/
# Before opening production: chown these two destination roots to www-data:www-data, then verify.
