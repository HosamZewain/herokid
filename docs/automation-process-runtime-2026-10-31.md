# Process execution increment — prepared, not live

**Later prepared continuation:** [identity generation, private preview and delivery receipts](automation-identity-preview-runtime-2026-10-31.md) supersedes the identity-adapter/template limitations below. This document records the preceding runtime increment; neither increment is deployed or activated.

31 October 2026. Branch `codex/automation-process-builder`, based on the builder/simulator increment `e8489e9b329dd74219bdfdc1a28dcc71116ff185`.

## Implemented

- Atomic order-event outbox: web checkout, completed mobile COD checkout and admin checkout emit one `checkout_created` event after the whole purchase is saved. Eloquent order status saves emit `status_entered` in the same transaction. Failed transactions roll back both business rows and events. Incomplete/pending online-payment attempts do not enroll.
- Event-based enrollment, immutable revision per run, one enrollment per workflow/checkout across revisions, durable steps and a checkout execution lock. A new activation starts after the current event high-water mark; there is no historical scan/backfill.
- Real adapters for the existing approved confirmation template, correlated waits, conditions, staff review, end, and two early status transitions (`new → new_confirmed`, `new_confirmed → under_review`). State changes retain status logs; payment-ledger entries and shipping/AI state are not rewritten.
- Separate execution history/statistics and guarded activation/pause/review controls at `/admin/order-automation/process/{automation}/live`. Simulations remain separate. Publishing an enabled process is refused; pause before publishing. Existing runs retain their original revision and activating authority.
- Dedicated existing-Redis connection/queue `order-process`, scheduler `order-process:tick`, and a **disabled example** Supervisor config in `ops/supervisor/herokid-order-process.conf.example`. No service was installed or started.
- Scheduler dispatch is leased for 180 seconds to avoid adding a duplicate job every minute while a worker is unavailable. Waiting runs revalidate their saved order inputs every ten minutes, even without a customer reply. This also detects a changed contact before a long response deadline.

## Safety and provider semantics

`ORDER_PROCESS_ENABLED=false` and `ORDER_PROCESS_CONFIRMATION_OWNER=legacy` are the defaults; `ORDER_PROCESS_AFTER_ORDER_ID` must be explicitly set before enabling execution. Deployment alone does not activate anything.

Confirmation ownership is separate from execution availability. With owner `process`, pausing the engine does **not** fall back to legacy sending. A recorded process also retains ownership if another child order is later added after a config rollback. Existing legacy receipts and waiting sends remain intact. A new process refuses a checkout already owned by legacy confirmation, and refuses to close/send for a phone with an unresolved legacy confirmation. Resolve that older contact manually before reconsidering the new run; no automatic replay is provided.

The existing transport is reused unchanged: `ar-EG`, four positional values, numeric total including shipping/discount, close with completed status, no `procedureId`, bounded safe close/501 recovery. A send claim is committed before contacting RoboDesk. One unique confirmation effect per checkout prevents a second workflow sending it again. A timeout/unknown result or an expired sending lease becomes `uncertain`, never an automatic resend. A late successful receipt is stored but does not silently resume an uncertain run.

Job timeout 110s < contact lock 130s < sending lease 150s < Redis retry_after 180s. Only local SQL transactions may retry deadlocks; the external send remains outside them. Before actions, the engine rechecks the original actor's permissions, order eligibility, contact and a saved fingerprint of order/items/financial/photo inputs. Local action failures roll back before recording a blocked run. Uncertain runs retain the checkout lock until investigated; there is no unsafe force-resend/force-release button.

History polling reuses the existing conversation workers: newest 50 interactions, no `after` cursor while a process awaits confirmation. Only inbound exact trimmed configured reply text counts. A known `replyTo` must match a stored template wamid. Otherwise typed confirmation requires the latest evidenced preceding template in the same interaction. Same-second typed replies, missing/mismatched template evidence and duplicate consumption do not advance. Exact known-template replies can arrive in another interaction. Receipts/context are encrypted; history screens do not expose phones, raw receipts or customer message bodies. Old and new confirmation consumers share message-lock/consumption safeguards.

## Scope limits / remaining work

This is an executable foundation, **not** completion of the 60–70% automation target.

- Live scope is checkout only. Story/product-specific child execution, joins, SKU/variant selection and production identity/save adapters still require implementation.
- Custom template names/field mappings remain available to design/simulate, but live policy currently accepts only the verified order-confirmation template and its established mapping. Template catalogue discovery and verified media/payment-review templates are not implemented.
- AI generation, canonical identity output registration, payment-proof ingestion/review and financial reconciliation are not wired to the generic engine. Customer text never marks an order paid. Do not call the legacy proof approval that assumes full payment/ready-for-print.
- Raw query-builder status updates, `saveQuietly`, direct SQL/imports do not emit events. Current web/admin/mobile creation entry points are covered; every new entry point must emit the final checkout event in its transaction. Do not backfill missed historical rows automatically.
- No production load/concurrent-worker stress test or real provider send was performed for this increment. Local lock/uniqueness/idempotency cases and both database engines were tested; remote and network failure ambiguity remains a review case.
- Receipt reconciliation and controlled recovery from blocked/uncertain sends require an explicit reviewed operator procedure; the UI deliberately offers no blind retry. No live activation is recommended until that handoff and the first bounded trial are separately approved.

## Verification

See local evidence `/tmp/herokid-process-runtime-evidence/` for exact logs. 253 PHP tests / 1,929 assertions passed on SQLite, and the same 253 / 1,929 on isolated MySQL. Includes 28 runtime tests plus web/mobile/admin completed-checkout hooks, legacy confirmation/workflow and order regression suites. All 26 changed PHP files passed PHP 8.3 syntax validation. Suites use synthetic databases, fake external HTTP and an isolated Docker network (MySQL) or no network (SQLite). PHP 8.3 syntax validation is separate from the PHP 8.5 full test runtime.

Frontend production build passed; all manifest targets exist and previous hashed assets were retained. Four browser/module builder tests passed. Authenticated editor/live pages were rendered from synthetic fixtures and checked at 1440px and 390px: no page overflow or JS errors. No customer data was used for these tests.

## Candidate deployment and activation gates (unapplied)

1. Revalidate production SHA/drift, backups and existing confirmation workers using the AWS runbook. The observed live SHA remained `adfaffa9841c79a16dd3d37383e2f3209a6736f1` at 13:46 UTC on 31 October. No production writes/config changes were made here.
2. After specific deployment approval, apply only the two additive process migrations in order (`160000` simulator, `170000` execution tables/activation metadata). No migration seeds jobs or changes customer orders. Keep the global runtime off and legacy ownership unchanged for a builder-only release.
3. Revalidate existing queue capacity/memory before approving the optional single additional worker on the current instance; no new infrastructure resources. Review config example against the live PHP binary/log paths. Its `autostart=false` is intentional until activation; approved restart-persistence configuration is a separate final step. Do not use a blanket Supervisor update/restart.
4. Before ownership transfer, reconcile legacy sends/waits and serialize the activation checkpoint with order creation. Capture a verified future-order boundary and exact published process/revision. Enable the runtime and activate only the approved scope; do not use 0 as a production boundary. Do not switch owner without a valid activated confirmation workflow and a working worker/poller. The high-water event fence alone is not a guarantee against an already in-flight checkout transaction; account for those transactions in the handoff.
5. Use a separately approved bounded trial; inspect actual receipt, polling, one state change, no duplicate side effects and ledger continuity. Deployment is not approval for customer sends or paid AI actions.
6. After any real execution, pause safely and retain all run/step/event/receipt/ownership tables for rollback. Preserve schema 2 isolation and ownership guards in any rollback candidate. Do not down-migrate, remove receipts, reactivate legacy sending for already-owned checkouts, or reset shared queues/cache. Do not assume an old code checkout alone is a safe rollback.

No additional recurring AWS resource is introduced. Existing CPU/Redis/MySQL load and WhatsApp/AI provider usage are separate operational considerations, not a claim of free messaging/generation.
