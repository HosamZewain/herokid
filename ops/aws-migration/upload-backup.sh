#!/bin/bash
# Upload only a verified production SQL archive after the approved IAM/profile transition.
set -euo pipefail
umask 077
file=${1:?Pass one verified /var/backups/herokid/herokid-*.sql.gz file}
case "$file" in /var/backups/herokid/herokid-[0-9]*.sql.gz) ;; *) exit 64;; esac
test -f "$file"
test ! -L "$file"
gzip -t "$file"
sha256sum --check "$file.sha256" >/dev/null
identity=$(aws sts get-caller-identity --query Arn --output text)
case "$identity" in arn:aws:sts::645314607155:assumed-role/HeroKid-Prod-EC2-S3-Role/*) ;; *) echo 'Production backup upload blocked by role identity'; exit 65;; esac
key="private/database-backups/$(basename "$file")"
checksum=$(sha256sum "$file" | cut -d' ' -f1)
aws s3 cp "$file" "s3://herokid-prod-media-2026/$key" --sse AES256 --metadata "sha256=$checksum" --only-show-errors
remote_checksum=$(aws s3api head-object --bucket herokid-prod-media-2026 --key "$key" --query Metadata.sha256 --output text)
test "$remote_checksum" = "$checksum"
echo 'Verified off-instance backup uploaded; retain local archive until restore validation.'
