# Preview and administrative media latency — 5 November 2026

## Diagnosis

AWS/GitHub base: b4de4e60018535e61827e90e16143b48e2c81590. Work is isolated in codex/preview-media-latency-20261104; primary mobile/UI edits remain intact.

The supplied manifest60 page returned200 in0.368s/8284bytes, but a real isolated browser waited49.9s for its first image (load50.2s; other lazy images still incomplete). Separate sequential image GETs showed8.9–13.6sTTFB for250–363KB protected images. Read-only same-original CLI profiling separated S3+SHA271ms/2434092bytes from watermark rendering7913ms. Numeric Nginx samples also showed some2–3MB originals taking15–24s to transfer even with upstream below0.5s. These are separate rendering and transfer costs, missed by earlier HTML-only measurements.

## Implementation

- Render the diagonal watermark text once, compose a seamless small tile, then repeat it across the image. Preserve mandatory raster watermark, maximum1400px and JPEG quality80. Bound Imagick nested threads to1 only during rendering and restore the prior process limit on both success and failure.
- Reuse private JPEG derivatives keyed by source SHA, all watermark settings and renderer version4. Every manifest GET still checks signature/expiry, run/order/gallery availability and the original SHA before reading the derivative. No public cache or original-image fallback. Local cache files live in order-thumbnails/protected, use temporary-file/rename publication and the existing seven-day derived-thumbnail cleanup. No customer originals/history deleted.
- Administrative scene/identity cards use400px thumbnails; clicking still opens the original. Existing order ownership, photo permissions and cross-order checks run before conditional304 responses. Remove duplicate metadata lookup when computing an existing thumbnail path.
- Counter projections read scalar order fields without hydrating all historical Order models. The existing hydration test failed110versus50 on the baseline too; the read path was fixed rather than weakening the assertion. No shared authorization/state cache.

## Tests and build

PHP8.3.3, isolated synthetic SQLite and MySQL8.4, HTTP requests prohibited: each238tests/2782assertions pass after the stamp change. The final resource-limit/failure checks additionally pass9tests/121assertions on each engine. Tests cover old/signed links, revoked availability after a warm cache, changed original SHA, watermark-settings invalidation, private thumbnails/304, cross-order denial, permissions, counters/filters, approvals and production recovery. Pint/diff and isolated lockfile npmci/build pass. Build manifest/assets unchanged, all historical assets retained. Synthetic visual QA confirms repeated diagonal watermark coverage.

## Publication and measured outcome

Initial application2d5e5d707240a2723a3601762c278459b456508f deployed22:08:26UTC; final applicationbd7590b21ec2d18edb0324635b41a9b141a127f6 deployed22:14:05UTC on4November (00:14:05Cairo,5November). Initial tile approach improved warm access but cold browser load remained14.955s; the final stamp/thread follow-up further improved cold access.

Same supplied page, actual isolated browser after final publication:

| Measurement | Cold derivatives | Repeated access |
| --- | ---: | ---: |
| HTML TTFB |331ms|244ms|
| Browser load |6864ms|2346ms|
| All five images loaded, including scrolling |7381ms|2831ms|
| Image statuses |All200|All200|

A final read-only CLI check of HK11-117 detail returned200 in1183.5ms; six private administrative thumbnails returned200 in62–317ms and13–26KB each. This is an authenticated application/media measurement, not a full authenticated-browser navigation. A separate under-load sample gave list3138ms/poll1103ms; the large list/detail HTML remains a further optimization opportunity. No universal latency or peak-load guarantee is claimed. Fontconfig warnings appeared in both old/candidate CLI probes; final live Nginx/Laravel windows had no new errors.

At22:15:19UTC the final45HTTP-request window had zero5xx, zero new Laravel/Nginx/FPM errors, scheduler success and failed_jobs83 unchanged. All eight required services active. All Laravel, AutoPilot and Codex worker PIDs preserved; only Blade cache and graceful FPM reload for the initial release, FPM-only for the final render correction. No environment/private config/routes/schema/OS config or infrastructure changes; no customer approval/message/generation POST or historical replay.

## Recovery

Checkpoints /home/ubuntu/herokid-before-preview-latency-20261105 and /home/ubuntu/herokid-before-preview-latency-final-20261105 contain prior source archives/SHA, private environment/config/routes, manifest, worker/log baselines and file hashes. Private bytes and modes verified unchanged. Compatible code rollback may restore reviewed source/Blade cache with graceful FPM reload; retain all customer/media/ledger/queue state. No DB restore, destructive cache clear, original deletion or worker replay is part of recovery. Current dated runbook section records cumulative final verification.
