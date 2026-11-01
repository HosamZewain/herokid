# Attachment-based payment review — deployed 1 November 2026

## Owner-approved behavior

Supersedes the proposed receipt-classification integration. RoboDesk adds no field, callback or receipt upload. Any inbound message with non-empty attachments after a verified `herokid_identity_review_payment` template, in its same interaction, is a signal for human review only. Staff inspect the original in RoboDesk and manually record any actual payment in HeroKid. No AI acknowledgment parsing, document authenticity claim, amount extraction or automatic payment/production change.

## Implementation

- Removed the previously prepared ReceiptClassification adapter and its tests; it was never deployed.
- Added two additive tables: per-process payment review and unique original-message references. No file copy/download, receipt URL or image content is stored by this feature. Existing encrypted conversation history is reused; review notes are encrypted.
- Accepted send receipts (including archived retry attempts) must match stored outbound template evidence and interaction. Message must follow that template. Same-second timing is ambiguous because incoming Meta timestamps have second precision; those cases are not auto-associated. Competing checkouts/runs sharing an interaction, absent evidence, changed phone/checkout, revoked actor, paused workflow, cancelled/deleted order or out-of-trial scope cannot create a review.
- A separate order panel says `مرفق من العميل — بانتظار مراجعة الدفع`, with attachment-message count and first observation time. It links to the existing RoboDesk tenant for lookup by customer phone. No receipt is fetched or displayed by this panel.
- Existing `robodesk.review_payments` plus `orders.view` authorize closing review, with mandatory note/confirmation. Closing marks this review closed only: it does not write a ledger transaction, change settlement/identity status, advance the process, generate, print or ship. The existing manual payment screen remains authoritative. A stale form cannot close a newer observed signal; duplicate close returns conflict. Closing intentionally stops this process's future attachment review, even if additional attachments arrive later.
- Existing bounded conversation background worker includes open review runs after `waiting_staff`. Full history uses limit50 and no after cursor while eligible, preserving delayed-webhook detection and message-id deduplication. Existing rate limits/contact locks/cooldowns apply. Ordinary order conversation refresh may continue after payment review closes for its existing independent purpose.
- Feature defaults OFF: `ORDER_PROCESS_PAYMENT_REVIEW_ENABLED=false`. Existing process activation boundary and trial order/workflow gates remain enforced. No blanket historical ingestion or general identity activation is authorized.

## Validation

93 tests/681 assertions passed on isolated SQLite: initial signal suite, active conversation scheduling, history and identity regressions. 56 tests/377 assertions passed on isolated MySQL with a dedicated synthetic database/user: initial signal suite, scheduling and history. An additional deposit/attachment-before-identity-confirmation regression was added afterward and tested separately. HTTP mocked; no production tests or provider sends. PHP8.3 syntax checks passed for changed application files. Vite built from this checkout's lockfile via npm ci; manifest targets exist and all previous tracked hashed assets restored. No generated asset content change. Existing font-resolution/chunk-size/Browserslist build warnings remain unchanged.

## Production boundary and deployment packet

Owner explicitly approved release **c66b3302168b635cc3ff436e7b8696f20bf83ce6** and activation only for **HK11-12/order2729/workflow8**. Deployed1November13:01:18–13:01:20 UTC (15:01 Cairo), two seconds of maintenance. Exact additive migration2026_11_01_140000 applied; all21 changed-file hashes verified. Environment/cached configuration comparison proves only ORDER_PROCESS_PAYMENT_REVIEW_ENABLED changed to true; existing trial scope, boundary2728, credentials, budgets and permissions preserved. Route/Blade caches rebuilt, FPM gracefully reloaded; four existing affected workers and scheduler restored and healthy.

Approved full history sync observed exactly3 attachment messages and created one pending review for run2 at13:04 UTC. A second full read kept3 signals and one review: no duplicate. No reviews for other orders. Authenticated checkout UI shows the pending-review panel,3 messages and the guarded close form. Paid balance remains0/unpaid, order status costomer_confirmed, approved identity678, one identity generation, one preview attempt and one consumed identity response. No receipt file copy, outbound message, AI generation or payment write occurred. Review remains open for staff.

Public HTTPS home/up/cart200 and www-to-apex redirect passed. Scheduler Result=success; process queues ready/reserved0; historical failed jobs81 unchanged. Bounded observation after deployment:448 requests/zero5xx. One preparation CLI error was logged before deployment because www-data could not traverse the staging home directory; migration had not run. Copied the reviewed migration to readable /tmp staging and resumed successfully. No subsequent application errors observed; no production directory permissions were broadened.

Private recovery checkpoint **/home/ubuntu/herokid-before-c66b330** retains source/env/config and before-state evidence. Backup **herokid-20261101T125837Z.sql.gz**,42,556,012bytes, gzip verified and uploaded to existing private/database-backups S3 prefix. Local SHA256 and S3 metadata match **e5ac1f2369a1c1b1e418c405d0d89254c38cb8a095515a140e3583b883288078**. No restore test in this rollout. No new recurring AWS resources; existing storage usage only.

Recovery: disable the new flag and preserve review/signal tables and audits; roll back only code/config through the runbook if required. Do not drop tables, replace the DB or undo real financial transactions. This feature never invokes the legacy approveProof handler that incorrectly assumes full payment and advances printing for this workflow.
