# Upload reliability and Admin performance — September 2026

## Changes

1. `RequestSettings` shares one settings-cache lookup across every Blade component and `setting()` call in a request. Setting model writes invalidate both shared and request-local values. WhatsApp templates reuse this map; no per-row settings queries remain.
2. `OrderFinancialStatistics` aggregates per checkout in SQL. It preserves the historical first-order delivery/payment rules, maximum group discount, zero-item legacy pricing fallback, payment clamp, custom status behavior, and mixed/deleted checkout handling. No stale financial cache is introduced. Both list statistics and dashboard financial/seven-day summaries use the aggregates.
3. Order views read only cached Bosta catalogs. A cold city catalog loads when the shipment form opens; district selection stays dependent on city. Saved official IDs are preserved. Failures offer a retry without delaying the rest of the page.
4. Bosta pickup synchronization is a unique queued job, not network work during navigation. Existing webhook and duplicate-pickup protections remain. Provider failure has a cooldown; the job timeout stays below the default database queue retry lease. A configured queue worker is required; failed jobs should be monitored if the provider is consistently slow. No shipping mutation permissions changed.
5. Dashboard GA4 summary loads through a separately authorized request, so external analytics do not hold up the page. Full analytics reports still run when explicitly opened.
6. Admin photo, approved-identity and product-preview cards use 400-pixel private thumbnails. Original download/signed URLs and customer-watermarked previews are unchanged. Responses explicitly call `setPrivate()` and revalidate authorization before returning 304. Derived files are removed on supported source deletion and aged out after seven days by the scheduler. No child image is exposed through public storage.
7. Admin attachments and product preview forms upload one file per request with progress. Successful files are removed from a partial retry batch. After success only the relevant section is replaced; navigation is not restarted. No production PDF/image is recompressed. Existing POST contracts, validation, permissions and non-JavaScript fallback remain.
8. Customer HEIC, optional storage, stale restoration, cancellation and CSRF fixes are described in `child-photo-upload-flow.md`.

## Measurements

Synthetic local Docker controller + Blade render, 25 visible rows, external requests disabled. Fixture inserts were rolled back. These are not production timings.

| Metric | Before | After |
| --- | ---: | ---: |
| Database queries, 25 matching orders, database cache | 714 | 25 |
| Site-settings cache reads | 662 | 1 |
| Per-row WhatsApp settings queries | 25 | 0 |
| Order models hydrated, 1,000 matches / 25 visible | 1,050 | 50 |
| Controller + render, 1,000 matches, database cache | about 902 ms | about 435 ms |

The cost of SQL filtering still depends on database size. These changes remove model hydration and repeated settings reads; they do not establish production latency guarantees.

## Verification

Final local run: **852 Laravel tests / 6,903 assertions passed**, plus **15 frontend/browser tests passed** (including the localhost-only upload). Laravel Pint, Vite production build, route/view compilation and `git diff --check` passed. No production deployment was performed.

- Full Laravel suite, including Agent auth, checkout, pricing, deletion/history and Bosta tests.
- Financial parity fixtures compare SQL with the existing historical calculation.
- Regression coverage includes database-backed settings caching/invalidation, safe upload restoration, private thumbnail permissions/304, AJAX file storage and deferred analytics/Bosta.
- Browser tests run a real HEIC decoder under the production CSP, storage denial, 419 refresh, cancellation, non-replayed errors and stale restoration.
- A separate localhost-only browser test submits a synthetic HEIC plus derivative to Laravel and verifies/restores/deletes it. No production customer upload is used for testing.

## Deployment

No database migration or Composer dependency was added. Frontend assets in `public/build` must be deployed together with PHP/Blade changes; the obsolete HEIC chunk is replaced. Hostinger does not need npm when using the committed build. Run `optimize:clear`, cache config/routes/views and restart existing queue workers after the pull.

Keep the existing Laravel scheduler and database/default queue worker active. `order-thumbnails:cleanup` is scheduled daily. Bosta jobs use the normal configured queue (database if the application is configured with synchronous execution), so they never execute inline with a page request. Do not change the site's queue to `sync` to address backlog. Check `queue:failed` and worker logs after deployment.

Production PHP/web-server settings cannot be changed from this repository. In hPanel, verify the **web** PHP runtime allows at least 50 MB per file and a larger POST envelope (e.g. upload_max_filesize=64M, post_max_size=80M), sufficient execution/input time and writable private storage. The customer cap remains 15 MB per original/derivative. A CLI `php -i` reading is not proof of web PHP settings. No promise is made that raising limits alone improves network bandwidth.

After release, test iPhone HEIC and Android JPEG, an interrupted upload, a long-idle page, a multi-file Admin upload, Bosta form opening, and order filters. Compare actual server timings/query counts with the local baseline. No production deployment or production upload verification was performed during implementation.
