# Production Studio Layout & Print

The Layout & Print phase turns approved Production Studio assets into versioned, private production files. It remains isolated from the original order and does not update order status, checkout, payment, delivery, or the existing Story Production Prompt.

## Required Inputs

- Exactly 13 Production Studio scenes numbered 1–13.
- One approved final `scene_image` for every scene.
- One approved front `cover_image`.
- Non-empty Arabic layout text for every scene.
- An optional manually uploaded back cover. If omitted, Studio creates a simple HeroKid back cover from the saved back-cover text and website.

## Admin Workflow

1. Approve the final front cover and one final image for every scene.
2. Open `الإخراج والطباعة`.
3. Choose the front cover and optional back cover.
4. Review the story text for each scene.
5. Select whether the text appears on the left or right A4 page and its vertical position.
6. Save settings and open `معاينة 28 صفحة`.
7. Click `توليد ملفات الإخراج والطباعة`.
8. Keep the Hostinger database queue worker running.
9. Download and inspect all four outputs.

## Outputs

Every generation creates a new immutable version under:

```text
storage/app/private/production-studio/projects/{project}/layout/v{version}/
```

Files:

- `reader-order.pdf`: 28 A4 portrait pages in reader order.
- `print-ready-a3-booklet.pdf`: 14 A3 landscape imposed sides for seven duplex sheets.
- `print-manifest.csv`: sheet, side, left page, right page, and flip direction.
- `proof-print-checklist.pdf`: manual proof checklist for production staff.

Generated files are served only through permission-checked admin download routes. They are never placed under `public/` and do not receive permanent public URLs.

## Reader Structure

- Page 1: front cover.
- Pages 2–27: 13 connected scenes, two consecutive A4 pages per scene.
- Page 28: back cover.

Each approved A3 scene image is split at its center into right and left A4 portrait pages. Arabic text is added as PDF text over the selected calm half; no AI-generated text is used inside the artwork.

The print canvas is A3 landscape at `4961 × 3508 px` equivalent placement at `300 DPI`. The two source halves use 2480 and 2481 pixels so the odd-width A3 canvas is preserved without claiming that both halves have an impossible identical integer width.

## Print Imposition

Default Arabic binding is from the right. Sheet 1 is imposed as:

- Front: Page 1 left, Page 28 right.
- Back: Page 27 left, Page 2 right.

The pattern continues inward for seven sheets. The manifest records every pair and defaults to duplex `short_edge` flipping. A left-to-right binding option is available when needed.

Before a production run, confirm the printer's duplex orientation with a low-cost proof. Printer drivers can label edge flipping differently.

## Queue and Hostinger

Layout generation is asynchronous and uses the existing database queue. No additional cron entry is required when the current queue script processes the `default` queue.

The existing `/home/u470070883/run-herokid-queue.sh` should contain one worker command only:

```bash
#!/bin/bash

cd /home/u470070883/domains/hero-kid.com/public_html || exit 1

/usr/bin/php artisan queue:work database \
  --queue=default \
  --stop-when-empty \
  --tries=2 \
  --timeout=300 \
  >> storage/logs/queue.log 2>&1
```

Hostinger cron can continue calling:

```text
/bin/bash /home/u470070883/run-herokid-queue.sh
```

The web UI polls the layout status and displays download links when the queue job finishes.

## PHP Requirements

The PDF renderer uses `mpdf/mpdf`. Production PHP must have:

- PHP 8.2 or newer.
- `mbstring`.
- `gd`.

The mPDF temporary directory is created under `storage/framework/cache/mpdf`, so `storage` and `bootstrap/cache` must remain writable.

## Admin-Managed Defaults

The main Site Settings page controls these defaults without code changes:

- `production_layout_website`: website printed on the back cover.
- `production_back_cover_text`: default back-cover message.
- `production_cover_subtitle_template`: cover subtitle; `{{child_name}}` is replaced with the order child name.

## Permissions

- `production_studio.layout_manage`: upload layout assets, save settings, preview, generate, and read status.
- `production_studio.layout_download`: download private generated production files.

The feature flag `HERO_KID_PRODUCTION_STUDIO_ENABLED=false` blocks these routes server-side with the rest of Studio.

## QA Behavior

Successful generation marks these checks as passed:

- cover exists
- back cover exists
- reader-order asset complete
- print-ready asset complete

It moves only the Studio project's current stage to `quality_check`. It does not mark proof printing, safe-area review, or the original order as complete.

## Tests

```bash
php artisan test --compact tests/Feature/ProductionStudioLayoutPrintTest.php
php artisan test --compact
npm run build
```

## Rollback

Disable Studio with the feature flag for an immediate non-destructive rollback. Existing layout rows and private files remain untouched. The migration rollback drops only `production_print_layouts` and its two permissions; it does not modify orders, stories, scenes, or generated image records.
