#!/usr/bin/env bash
# Run from the real Hostinger application root with an explicitly verified release SHA.
set -Eeuo pipefail
umask 077

PHP_BIN="${PHP_BIN:-/usr/bin/php}"
BRANCH="codex/mobile-basic-commerce-release"
RELEASE_COMMIT="${1:?Usage: bash deploy-mobile-basic-commerce.sh RELEASE_COMMIT}"
APP_DIR="/home/u470070883/domains/hero-kid.com/public_html"
BACKUP_ROOT="/home/u470070883/backups/hero-kid-mobile"
MIGRATION="database/migrations/2026_09_24_000001_add_phone_verified_at_to_users.php"

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
"$PHP_BIN" -r '
require "vendor/autoload.php";
$values = Dotenv\Dotenv::parse(file_get_contents(".env"));
if (filter_var($values["MOBILE_OTP_ENABLED"] ?? false, FILTER_VALIDATE_BOOLEAN)) {
    fwrite(STDERR, "Stopped: set MOBILE_OTP_ENABLED=false in .env first.\n"); exit(1);
}
'

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

# Apply the additive migration using the OLD application before its new User cast runs.
git show "$RELEASE_COMMIT:$MIGRATION" > "$HEROKID_RELEASE_BACKUP/$(basename "$MIGRATION")"
"$PHP_BIN" artisan down --retry=60
trap 'echo "Deployment stopped. Site may remain in maintenance. Do not restore the database blindly; review the error."' ERR
"$PHP_BIN" artisan migrate --force --realpath --path="$HEROKID_RELEASE_BACKUP/$(basename "$MIGRATION")"
"$PHP_BIN" artisan tinker --execute='if (! Illuminate\Support\Facades\Schema::hasColumn("users", "phone_verified_at")) { throw new RuntimeException("Required users column is missing."); }'

# No reset/clean/forced checkout: untracked hosting files and media are preserved.
git switch --detach "$RELEASE_COMMIT"
test "$(git rev-parse HEAD)" = "$RELEASE_COMMIT"
composer install --no-dev --optimize-autoloader --no-interaction
test -f public/build/manifest.json
"$PHP_BIN" artisan config:clear
"$PHP_BIN" artisan route:clear
"$PHP_BIN" artisan view:clear
"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan tinker --execute='if (config("services.mobile_otp.enabled")) { throw new RuntimeException("OTP must remain disabled."); }'
"$PHP_BIN" artisan route:list --path=api/v1/cart/items/batch
"$PHP_BIN" artisan route:list --path=api/v1/checkout/quote
"$PHP_BIN" artisan queue:restart
"$PHP_BIN" artisan up
trap - ERR
git log -1 --oneline
echo "Deployment completed. Run the live mobile contract checks next."
