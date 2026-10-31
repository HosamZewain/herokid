# Order message history and reviewed manual retries — 31 October 2026

Owner requested send acceptance, delivery, attempt counts/history and failed-attempt resend on the order page, then explicitly approved deployment. Base live application: `daae3bd537bc80c6675d7acc923f74b3275b9123`. Release branch `codex/order-process-trial-readiness` includes the inactive single-order trial guard `2edfcb7`; no process/identity activation is part of this release.

## Behavior

Both checkout and individual order details display a compact shared automation-message history panel. It reads existing confirmation receipts immediately, and records each future process WhatsApp send separately. Stages distinguish RoboDesk acceptance from WhatsApp delivery, including unknown and unreachable. Details include Cairo timestamps, attempt count, requesting employee and manual retry reason. No customer content, financial totals, provider IDs, private URLs or raw provider errors are passed to this panel.

Retries require `orders.view`, `orders.update`, **`order_automation.execute`**, active user, CSRF, reason and explicit checkbox acknowledging a new message and possible conversation closure. No new permission grants. Existing owner user1 did not have execute permission at readiness inspection; that account can inspect history but needs a separately authorized permission change to use retry. Do not grant broad AI execution implicitly.

Only definite no-send failures (disabled/invalid template, missing setup, exhausted exact501 race) or provider-confirmed failed delivery are eligible. Unknown outcomes, processing, delivered, accepted-but-unknown/sent, changed contacts/states, inactive processes, expired response waits and superseded attempts are blocked. A queued retry rechecks permission/current context and original failure before contacting RoboDesk. There is no automatic retry of an uncertain send.

Legacy confirmation retries are new deduplicated outbox events linked to the original; old receipts remain intact. Process retries use an additive encrypted attempt ledger and version-checked pending job. They resend the message node only, never replay generation/status/financial steps. Response matching retains old accepted wamids and checks exact phone/conversation/template evidence, so late replies cannot be reassigned or consumed twice. Retry dispatch counts are not the number of internal safe no-send501 HTTP retries. Historical aggregated attempts are labeled explicitly where per-attempt details were never recorded.

## Validation

129 tests /655 assertions passed on isolated SQLite and MySQL with synthetic data and fake outbound HTTP. Includes permission/revocation, uncertain-state denial, duplicate jobs/clicks, cross-checkout denial, changed original delivery, retained receipts, late old-template confirmation, no identity regeneration and authenticated rendering without Bosta permission. PHP8.3 syntax checks passed. Lockfile npm ci/build passed without an asset change; existing Vite font/large-chunk warnings remain. Synthetic browser verified stage chips, expanded attempt table and explicit retry form. No real customer sends/retries or paid AI calls for validation.

## Deployment / recovery

Apply only `2026_10_31_210000_create_order_process_message_attempts.php`; no historical backfill/deletion. Preserve .env and private cached config. Gracefully pause scheduler and refresh existing default + conversation workers, because both can load the changed pollers; leave the stopped automation programs stopped. Resume the same scheduler timer after route/Blade cache and FPM reload. The pending migration is additive; do not drop its table on rollback.

Fresh DB backup `herokid-20261031T185735Z.sql.gz`: gzip/hash validated, 41,223,863 bytes; matching S3 size and SHA256 `0b1f2d5373037a8ff71c0e899b9c47a811d59f6d1f1e97fdcb92ea2c97f15e9b`. Existing backup service/bucket only; no new recurring resource. This archive was not restored in a new test.

After retries exist, returning to old code requires preserving the ledger and reconciling pending retry jobs/old-template responses; do not blindly revert while a send is queued, running or uncertain. No database restoration. Actual live SHA, checkpoint and validation evidence are appended after deployment.

## Continuing identity readiness (prepared, not activated)

RoboDesk catalogue and Meta UI verified `herokid_identity_review_payment` approved, utility, Arabic; send language remains ar-EG. Eight positional variables and two quick replies match the implemented contract. Identity setting currently uses OpenAI gpt-image-2,1536x1024,medium; local configured estimate $0.041/image is neither a live provider price guarantee nor a spending cap. New trial guard can pin exactly one checkout containing one nondeleted order and one workflow, including rejecting deleted siblings. No dedicated process/AI workers started, no configuration/permissions enabled, no new paid trial authorized here.

Read-only investigation of HK10-621 found one matching accepted/delivered confirmation template and a customer reply referencing its wamid, while event22594 remains unknown. Preserve this evidence; reconcile the receipt under a separately reviewed production action rather than resending. This release does not change that event or the order status.
