# Approved homepage integration

## Scope

Integrates the approved vivid blue/aqua HeroKid homepage into the existing Laravel application, including real story/product cards, real purchase URLs and prices, shared public navigation/footer, and desktop/mobile **دليل HeroKid**.

Branch: `codex/homepage-approved-integration`.
Base main commit: `f66153de55ce4bb89be6b2999f24099290b54cce`.
Local Laravel review: `http://localhost:8088/`.

No schema migration, new environment variable, new Composer/npm dependency, production backfill, payment workflow or Agent API change is introduced. The original dirty checkout remains untouched. Main was not modified during this integration.

## Architecture and editable content

- `Front\HomeController` reuses the sales-ranking and unified-storefront services; cards contain current public database records and real effective prices, including crossed-out prices only for active discounts.
- `front/homepage.blade.php` implements the selected section anatomy. Existing package carousel, store sections, child-identity entry, active FAQs/testimonials and section visibility are retained.
- `front/partials/header`, `guide-menu` and `footer` are shared by the public layout. Desktop/mobile guide links use existing routes.
- `front-theme.css` is scoped to `herokid-front`, not admin. The homepage CSS/art are loaded only on the homepage. Existing cart, story personalization, upload and checkout handlers are unchanged.
- Admin **الإعدادات → محتوى الصفحة الرئيسية** edits the new hero using `home_workshop_title_1`, `home_workshop_title_2`, `home_workshop_subtitle`. Existing old hero settings remain stored. Visibility controls include a new categories section. Existing child-identity copy, store sections, FAQs, packages, footer and contact settings still apply.
- Existing SEO settings are preserved. Local preview can use HTTP localhost images; production canonical/image URL policy still uses HTTPS.
- Approved WebP artwork is in `public/images/homepage`; self-hosted Cairo has its OFL license in `public/fonts/OFL.txt`; unmodified Heroicons have their MIT license in `public/images/icons/heroicons/LICENSE`.
- `public/build` contains compiled versioned assets and manifest. Node is not required on Hostinger to deploy these files.

## Verification

See [design-qa.md](../design-qa.md) for screenshots, comparison history, browser checks, exact test results and residual test gaps. Full Laravel suite and Composer script each passed **1094 tests / 8655 assertions**. A synthetic local mobile purchase completed successfully.

## Hostinger deployment — only when approved

These commands deploy the latest pushed integration branch, not main, and display the selected commit. They preserve untracked hosting files and stop if tracked files have local modifications. For a strictly pinned release, use the verified release SHA from the handoff instead of `FETCH_HEAD` in the checkout command. An untracked-file collision will make Git stop safely; do not delete hosting files to bypass it.

Run the block as one unit; the subshell keeps failures from closing the SSH login shell:

```bash
(
    set -e
    cd /home/u470070883/domains/hero-kid.com/public_html || exit 1
    PHP_BIN="/usr/bin/php"
    BRANCH="codex/homepage-approved-integration"

    if ! git diff --quiet || ! git diff --cached --quiet; then
        echo "Deployment stopped: tracked files contain local changes."
        git status --short --untracked-files=no
        exit 1
    fi

    git fetch origin "refs/heads/$BRANCH"
    git checkout -B "$BRANCH" FETCH_HEAD
    git rev-parse HEAD

    composer install --no-dev --optimize-autoloader --no-interaction
    "$PHP_BIN" artisan down --retry=60
    trap '"$PHP_BIN" artisan up' EXIT

    "$PHP_BIN" artisan optimize:clear
    "$PHP_BIN" artisan migrate --force
    "$PHP_BIN" artisan config:cache
    "$PHP_BIN" artisan route:cache
    "$PHP_BIN" artisan view:cache
    "$PHP_BIN" artisan queue:restart

    "$PHP_BIN" artisan up
    trap - EXIT
    echo "Deployment completed. Check home, guide, product/story and cart."
)
```

The integration itself adds no migration; `migrate --force` is the normal safeguard for any previously pending application migrations. Existing storage links are left alone. Existing writable storage/cache permissions must already be correct, as in the current installation.

After deployment, verify both desktop/mobile navigation, actual product/story prices, a real product customization form and cart totals without placing a real purchase. Clear browser cache once if it has cached the old document.

For rollback, keep the pre-deployment release SHA and redeploy that verified release using the same cache/build process. Do not reset production orders/data or delete uploaded files.

No production deployment was performed by this task.
