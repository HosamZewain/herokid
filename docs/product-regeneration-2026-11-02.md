# Per-item product image regeneration — deployed 2 November 2026

Status: **deployed** with owner approval at11:04:48UTC2November2026, application01e6f6f684d00cca57496e66ca13323fbf2e1286. Authenticated button and runtime health verified; no production regeneration test performed. User requested an explicit button after editing one product in a multi-product checkout. No production generation, order return, messaging or data mutation was performed for this feature.

## Behavior

- Staff save the updated product/order data first, then select **إعادة التوليد** under the relevant single-image product production prompt. Existing permissions `orders.update`, `orders.photos.view`, and `orders.production_prompt.manage`, active non-agent staff only.
- Existing supported puzzle/mug items only, with a previous completed/review/blocked attempt. Canonical `ProductProductionPrompt` and current item personalization/variant, child photos and order instructions are captured anew. Other units are untouched.
- Each attempt is immutable history linked by `previous_run_id`, with an increasing item-local attempt number, initiating staff ID, encrypted input snapshot, provider receipt, status/error and cost evidence. No request sends to the provider synchronously.
- Unique parent-attempt and active-item reservations plus checkout/item locks prevent a repeated form/double click from creating multiple paid submissions. A later deliberate click from the latest completed form starts a new attempt. Uncertain results cannot be retried here.
- The existing worker executes exactly one provider attempt. Current fingerprint, manual handoff token, printing/shipping stage and existing output baseline are checked before sending and before publishing. Changes after submission retain the output for review without replacing previous previews.
- Successful output appends separate private attachment and preview objects using the new run ID. Existing images, payments, statuses and customer messages are unchanged. Both old and new preview images remain visible until staff curate them.
- Explicit regeneration works while the checkout remains manually held. Only this requested run overrides that hold; ordinary queued siblings remain paused. While it is active/uncertain the existing Automation ownership guard prevents conflicting staff edits. Terminal success/review/blocked releases ownership; the manual hold remains.
- Audit records the initiating staff member and attempt link; each worker transition is separately attributed to Automation. Returning a manually held checkout checks the latest attempt per item and never replays a blocked manual regeneration.

## Verification

- SQLite focused puzzle/handoff: 34 tests, 276 assertions.
- Isolated MySQL focused: 34 tests, 276 assertions; broader product components, confirmation, identity/payment review, handoff and Codex scenes: 212 tests, 1321 assertions.
- Scenarios include three generated products with one changed; previous bytes/references unchanged; updated prompt/photos used only for the requested item; duplicate submission before/after completion; unsafe/stale requests; permission and item scope; changed inputs/output/manual hold; uncertain provider result; retry of a safely blocked preflight; successful manual return after regeneration; rendered per-item form.
- Fake image provider only, HTTP stray requests disabled, isolated databases, no customer media. PHP 8.3 syntax verified. Production assets rebuilt using this checkout's lockfile and manifest checked; outputs byte-identical to committed assets. Previous hashed assets retained.

## Deployment and recovery procedure (approved and executed)

1. Revalidate actual AWS SHA/dirty state; compare this exact branch with production. Base application was verified as 29ac3cc in the preceding release; do not assume it remains current.
2. Fresh verified backup and source/config checkpoint. Preserve `.env`, private cached runtime flags and all provider/output evidence.
3. Gracefully drain affected Laravel workers and fence scheduler plus conflicting writes during the narrow migration/code switch. Do not interrupt live image calls or the separate Codex worker. Apply only `2026_11_02_140000_add_product_generation_attempts.php` after inspecting pending migrations.
4. This migration replaces one-row-per-item uniqueness with item/attempt uniqueness and adds unique active-item and previous-run reservations. It backfills active reservations on existing queued/processing/uncertain runs; it does not enroll or replay any work.
5. Rebuild only relevant route/view caches, gracefully refresh affected Laravel workers with reviewed retry/timeout ordering, restore scheduler. No config or service-resource change is needed.
6. Verify deployed SHA/file hashes, schema/indexes, authenticated read-only UI and public HTTPS/new logs. Do not click a production regenerate button without a specific generation instruction.
7. Preserve all attempt history. Migration down refuses once any item has multiple attempts. After new attempts exist, use a forward fix; older workers neither understand active reservations nor preserve latest-attempt semantics. Do not restore a database or delete images to roll back.

Live evidence: all15 changed hashes matched;19existing runs unchanged, no new attempts; migration119 successful, active reservations valid; private env/config unchanged. Backup20261102T110042Z verified locally and off-instance; checkpoint `/home/ubuntu/herokid-before-regeneration-01e6f6f`. Four Laravel workers refreshed gracefully, Codex PID unchanged, scheduler healthy; home/up/cart200 and failedjobs81 unchanged.
