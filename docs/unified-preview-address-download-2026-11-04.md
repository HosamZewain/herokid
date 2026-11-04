# Unified customer previews, selected downloads and delivery address — 2026-11-04

Scope: consistent light customer preview with mandatory raster watermarks, child/product labels and checkout reference; selected original admin files downloaded as ZIP; Bosta-format delivery address editing inside the quick contact modal.

- New automatic links use `/order-preview/review-{manifest}`. Previously signed review links redirect with the same expiry and immutable manifest. Old token galleries redirect only when their exact product image set matches the eligible manifest; otherwise they retain all gallery images in the same shared view. Revoked, expired and unavailable links keep existing access restrictions.
- Both legacy and canonical image routes watermark server-side. Originals and approval snapshots remain unchanged. Blank watermark configuration uses HeroKid Preview. Font is bundled Cairo, avoiding platform font dependencies.
- ZIP download uses existing view permission and per-order visibility. All selected IDs must belong to the checkout. Up to 100 originals / 512 MiB per batch; bounded local temporary files removed after response. Selection order and duplicate filenames are preserved. No destructive form submission.
- Contact modal exposes optional Bosta address editing (city, dependent district, street/building, additional details). Catalog access is order-scoped and requires orders.update. Server validates the city/district pair; trusted names/zone IDs replace address fields across the checkout. Existing contacts, photos, products, payment ledger and shipping price remain intact. Existing Bosta shipments are not changed automatically; the form explicitly directs staff to review an existing waybill.
- Address changes retain existing automation mutation guards. No paused order restart, historical replay or new customer message is part of this release.

Validation: PHP 8.3, isolated SQLite and MySQL 8: 106 tests / 1239 assertions each (preview runtime, galleries, attachments/ZIP, quick edits). Six JS tests pass. Local rendered desktop/mobile preview, select-all ZIP state, and Bosta address modal checked in browser with synthetic data. Vite lockfile build; old hashed assets retained.

Deployment: code/routes/assets only; no migration, dependency install, config change or external service mutation. Record exact deployed SHA and checkpoint in AWS runbook after verification. Production verification is read-only; no customer data edited as a test.
