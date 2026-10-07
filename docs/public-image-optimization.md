# Public display image optimization

## Scope and safety

This is a display-only pipeline for public site artwork, story covers, product/variant galleries, and package covers. Original files, model image paths, Agent API image contracts, child/customer photos, production references, attachments, PDFs, pricing, and checkout behavior are unchanged.

- Bundled artwork has committed, content-versioned WebP variants and a manifest under `public/images/optimized/`. Build with `php artisan images:build-site-variants` when changing that artwork.
- Uploaded catalog images use the existing configured `media.public_disk` (Hostinger: `public`). Source files are streamed to `media.processing_disk` for encoding. Permanent disks never require a filesystem `path()`.
- Uploaded derivatives live under `display-images/v1/{content-fingerprint}/{width}.webp`. A separate `public_image_variants` table holds source checksums and derivative metadata. Original paths remain the authority everywhere outside display rendering.
- Widths: 320, 640, 960, 1440, 1920, capped at the source width. WebP quality: 82. Larger encoded replacements are not saved. Alpha and aspect ratio are preserved; orientation is applied and unnecessary metadata stripped from derivatives only.
- Animated/unsupported sources and sources over the safety limits retain their originals. Failed generation never publishes incomplete metadata. Newly created derivatives are cleaned if persistence fails; existing derivatives/originals are not deleted.
- GET requests only read metadata. They never encode, create rows, or enqueue jobs. Metadata is batched for catalog views; remote object existence is not checked on page render.
- `srcset`/`sizes` let the browser choose a display size. Product gallery/variant selection replaces responsive sources correctly. Missing derivatives fall back to the original; existing story-cover recovery and catalog placeholders remain available.

## New uploads

Catalog image create/change events enqueue `GeneratePublicImageVariants` after commit. Price/name/order changes do not enqueue image processing. Queue failures do not prevent the catalog edit from saving. The original is displayed while optimization is pending.

No required environment variables or new dependencies. The existing queue worker must run. `PUBLIC_IMAGES_QUEUE_CONNECTION` is optional; a `sync`/empty setting falls back to `database` so image encoding never runs inline in a web request. The worker should allow the job's 120-second timeout; use the established queue/cron configuration, not an additional overlapping worker.

## Existing catalog images

After deployment/migrations, inspect the counts first:

```bash
/usr/bin/php artisan images:optimize-catalog
```

Generate a small, sequential batch outside web requests (suitable for shared hosting):

```bash
/usr/bin/php artisan images:optimize-catalog --apply --sync --limit=20
```

Repeat and review `Pending` until all eligible sources are prepared. Repeating successful work is safe/idempotent. Unsupported, missing, or oversized sources are reported and continue using originals. If a batch contains skipped files, a larger reviewed `--limit` can include later sources; the command never replaces the originals. Use `--force` only to explicitly recheck previously prepared paths after an external overwrite.

Alternatively, with the existing queue worker running:

```bash
/usr/bin/php artisan images:optimize-catalog --apply --limit=200
```

This reports queued operations, not completed encoding. Run the dry run again after the worker finishes. Do not enqueue repeated batches while the same batch is still pending.

## Public copy

The additive copy migration updates only exact legacy defaults in the How It Works steps, its description, and footer. Custom administrator copy and image settings are preserved. The page now distinguishes personalized previews from ready-made products and mixed carts; it does not promise the same production timeline for every item.

## Deployment

Run Composer install, maintenance mode, cache clear, `php artisan migrate --force`, and rebuild Laravel caches using the normal deployment procedure. The responsive site assets and Vite build are committed; Node/ImageMagick processing of bundled images is not needed on the server. Bring the site up before running existing-catalog batches.

No production/customer backfill or order updates occur. Optimization metadata and derivative files are additive. Rolling back display code restores original-image rendering without changing orders or original media.

Measured bundled 640px variants save 82–92% versus their source files. For example: mobile hero 311,616 → 57,052 bytes; activity feature 292,244 → 42,948 bytes. Actual browser downloads depend on viewport and pixel density.

A downloaded public catalog JPG was tested locally without modifying production: 693,927 → 136,842 bytes at 640px (80% smaller), or 47,298 bytes at 320px.

## Verification (2026-10-08)

- `composer test` (the complete Laravel suite, isolated Docker MySQL): **1116 passed, 8981 assertions**.
- Responsive image/gallery and story-cover recovery JavaScript tests: **8 passed**.
- Pint on the 18 changed PHP files and `git diff --check`: passed.
- Local browser checks at 390px and desktop: responsive hero sources, no horizontal overflow, no observed broken images, correct variant image/price, successful cart addition, updated guide/footer and social icons. No live orders, payments, media, or production data were modified.
