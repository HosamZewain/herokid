#!/usr/bin/env bash
# Scoped Hostinger release. No migrations, media deletion, or frontend rebuild.
set -Eeuo pipefail
umask 077

PHP_BIN="${PHP_BIN:-/usr/bin/php}"
BRANCH="codex/admin-quick-add-story"
RELEASE_COMMIT="${1:?Usage: bash deploy-admin-quick-story.sh RELEASE_COMMIT}"
APP_DIR="/home/u470070883/domains/hero-kid.com/public_html"
BACKUP_ROOT="/home/u470070883/backups/hero-kid-quick-story"

[[ "$RELEASE_COMMIT" =~ ^[0-9a-f]{40}$ ]] || exit 1
cd "$APP_DIR"
test -f artisan && test -f composer.json && test -f .env
git rev-parse --is-inside-work-tree >/dev/null
if ! git diff --quiet || ! git diff --cached --quiet; then
    echo "Stopped: tracked local changes must be reviewed first."
    git status --short --untracked-files=no
    exit 1
fi
test ! -f storage/framework/down || { echo "Stopped: site was already in maintenance."; exit 1; }
git fetch origin "$BRANCH"
test "$(git rev-parse FETCH_HEAD)" = "$RELEASE_COMMIT"
PREVIOUS_COMMIT="$(git rev-parse HEAD)"
git merge-base --is-ancestor "$PREVIOUS_COMMIT" "$RELEASE_COMMIT" || {
    echo "Stopped: release would omit current deployed changes."
    exit 1
}
test -z "$(git diff --name-only "$PREVIOUS_COMMIT" "$RELEASE_COMMIT" -- database/migrations composer.json composer.lock)" || {
    echo "Stopped: this deployment does not cover migrations or dependency changes."
    exit 1
}

mkdir -p "$BACKUP_ROOT"
chmod 700 "$BACKUP_ROOT"
HEROKID_RELEASE_BACKUP="$(mktemp -d "$BACKUP_ROOT/release-$(date -u +%Y%m%d-%H%M%S)-XXXXXX")"
export HEROKID_RELEASE_BACKUP
printf '%s\n' "$PREVIOUS_COMMIT" > "$HEROKID_RELEASE_BACKUP/previous-commit.txt"
cp .env "$HEROKID_RELEASE_BACKUP/.env.backup"
if test -f .htaccess; then cp .htaccess "$HEROKID_RELEASE_BACKUP/root.htaccess.backup"; fi
git archive "$PREVIOUS_COMMIT" | gzip > "$HEROKID_RELEASE_BACKUP/code.tar.gz"
"$PHP_BIN" artisan tinker --execute='app(App\Services\DatabaseExports\DatabaseDumpWriter::class)->write(getenv("HEROKID_RELEASE_BACKUP")."/database.sql");'
test -s "$HEROKID_RELEASE_BACKUP/database.sql"
gzip "$HEROKID_RELEASE_BACKUP/database.sql"
gzip -t "$HEROKID_RELEASE_BACKUP/database.sql.gz"
echo "Backup completed: $HEROKID_RELEASE_BACKUP"

"$PHP_BIN" artisan down --retry=60
trap 'echo "Deployment stopped. Site may remain in maintenance; review the error before reopening. No database restore was attempted."' ERR
# Keep private backups restricted; use standard permissions for the code checkout.
umask 022
git switch --detach "$RELEASE_COMMIT"
test "$(git rev-parse HEAD)" = "$RELEASE_COMMIT"
# Backups stay private, but newly checked-out application files must be readable
# by Hostinger's static-file server. A private umask produces 600/700 on checkout.
find public/build -type d -exec chmod 755 {} +
find public/build -type f -exec chmod 644 {} +
composer install --no-dev --optimize-autoloader --no-interaction
"$PHP_BIN" -r '
$manifest = json_decode(file_get_contents("public/build/manifest.json"), true, 512, JSON_THROW_ON_ERROR);
foreach ($manifest as $entry) {
    foreach (array_merge([$entry["file"]], $entry["css"] ?? []) as $asset) {
        if (! is_file("public/build/".$asset)) { throw new RuntimeException("Missing built asset"); }
    }
}
'
"$PHP_BIN" artisan config:clear
"$PHP_BIN" artisan route:clear
"$PHP_BIN" artisan view:clear
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan route:list --name=admin.orders.groups.stories.store
"$PHP_BIN" artisan queue:restart
"$PHP_BIN" artisan up
trap - ERR
git log -1 --oneline
echo "Deployment completed. Verify Add Story from the admin order page."
