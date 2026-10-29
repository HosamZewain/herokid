#!/bin/bash
set -euo pipefail
umask 077
test "$(id -u)" = 0
exec 9>/run/lock/herokid-db-backup.lock
flock -n 9 || exit 0
destination=/var/backups/herokid
mkdir -p "$destination"
chmod 700 "$destination"
test "$(df --output=avail -B1 "$destination" | tail -1)" -gt 2147483648
stamp=$(date -u +%Y%m%dT%H%M%SZ)
for database in herokid herokid_staging; do
    target="$destination/$database-$stamp.sql.gz"
    test ! -e "$target"
    mysqldump --single-transaction --quick --no-tablespaces --routines --triggers --events "$database" | gzip > "$target.partial"
    gzip -t "$target.partial"
    mv "$target.partial" "$target"
    sha256sum "$target" > "$target.sha256"
done
echo "Verified local database backups completed: $stamp"
# No automatic pruning until off-instance recovery and retention are approved.
