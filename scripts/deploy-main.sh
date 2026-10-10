#!/usr/bin/env bash
# Hostinger main release, pinned to an explicitly reviewed commit.
# Schema/dependency changes need a separately reviewed deployment plan.
set -Eeuo pipefail
umask 077

PHP_BIN="${PHP_BIN:-/usr/bin/php}"
RELEASE_COMMIT="${1:?Usage: bash scripts/deploy-main.sh FULL_MAIN_COMMIT}"
APP_DIR="${HEROKID_APP_DIR:-/home/u470070883/domains/hero-kid.com/public_html}"
BACKUP_ROOT="${HEROKID_BACKUP_ROOT:-/home/u470070883/backups/hero-kid-main}"

[[ "$RELEASE_COMMIT" =~ ^[0-9a-f]{40}$ ]] || exit 1
cd "$APP_DIR"
test -f artisan && test -f composer.json && test -f .env
test "$(pwd -P)" = "$(git rev-parse --show-toplevel)"
if ! git diff --quiet || ! git diff --cached --quiet; then
    echo "Stopped: tracked local changes must be reviewed first."
    git status --short --untracked-files=no
    exit 1
fi
test ! -f storage/framework/down || { echo "Stopped: site was already in maintenance."; exit 1; }
git fetch origin main
test "$(git rev-parse FETCH_HEAD)" = "$RELEASE_COMMIT" || {
    echo "Stopped: origin/main changed; use the verified release commit."
    exit 1
}
PREVIOUS_COMMIT="$(git rev-parse HEAD)"
git merge-base --is-ancestor "$PREVIOUS_COMMIT" "$RELEASE_COMMIT" || {
    echo "Stopped: main would omit current deployed changes. No checkout performed."
    exit 1
}
if git show-ref --verify --quiet refs/heads/main; then
    git merge-base --is-ancestor main "$RELEASE_COMMIT" || {
        echo "Stopped: local main contains divergent commits; review them first."
        exit 1
    }
fi
test -z "$(git diff --name-only "$PREVIOUS_COMMIT" "$RELEASE_COMMIT" -- database/migrations composer.json composer.lock)" || {
    echo "Stopped: schema/dependency changes need a reviewed deployment plan."
    exit 1
}

mkdir -p "$BACKUP_ROOT"
chmod 700 "$BACKUP_ROOT"
exec 9>"$BACKUP_ROOT/deploy.lock"
flock -n 9 || { echo "Stopped: another main deployment is running."; exit 1; }
HEROKID_RELEASE_BACKUP="$(mktemp -d "$BACKUP_ROOT/release-$(date -u +%Y%m%d-%H%M%S)-XXXXXX")"
export HEROKID_RELEASE_BACKUP
printf '%s\n' "$PREVIOUS_COMMIT" > "$HEROKID_RELEASE_BACKUP/previous-commit.txt"
git symbolic-ref --short -q HEAD > "$HEROKID_RELEASE_BACKUP/previous-branch.txt" || true
cp .env "$HEROKID_RELEASE_BACKUP/.env.backup"
if test -f .htaccess; then cp .htaccess "$HEROKID_RELEASE_BACKUP/root.htaccess.backup"; fi
git archive "$PREVIOUS_COMMIT" | gzip > "$HEROKID_RELEASE_BACKUP/code.tar.gz"
gzip -t "$HEROKID_RELEASE_BACKUP/code.tar.gz"

"$PHP_BIN" artisan down --retry=60
trap 'echo "Deployment stopped. Site may remain in maintenance; review the error before reopening. No automatic code or database rollback was attempted."' ERR
"$PHP_BIN" artisan tinker --execute='app(App\Services\DatabaseExports\DatabaseDumpWriter::class)->write(getenv("HEROKID_RELEASE_BACKUP")."/database.sql");'
test -s "$HEROKID_RELEASE_BACKUP/database.sql"
gzip "$HEROKID_RELEASE_BACKUP/database.sql"
gzip -t "$HEROKID_RELEASE_BACKUP/database.sql.gz"
echo "Backup completed: $HEROKID_RELEASE_BACKUP"

# Private backups retain 600/700 permissions; public assets need 644/755.
umask 022
if git show-ref --verify --quiet refs/heads/main; then
    git switch main
    git merge --ff-only "$RELEASE_COMMIT"
else
    git switch --create main "$RELEASE_COMMIT"
fi
git branch --set-upstream-to=origin/main main
test "$(git rev-parse HEAD)" = "$RELEASE_COMMIT"
test "$(git branch --show-current)" = main
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
"$PHP_BIN" artisan route:list --name=admin.orders.groups.items
"$PHP_BIN" artisan queue:restart
"$PHP_BIN" artisan up
trap - ERR
git log -1 --oneline
echo "Deployment completed on main. Verify order additions/removal and public assets."
