# Attachment-based payment review — prepared 1 November 2026

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

Read-only SSH still reports live ae52dcbdf50a56f4ec3c454fa3f065df4e0f1e26. This work is prepared/unpublished to production. No order/payment mutation, worker refresh, configuration activation or message send performed. A deployment approval is required for this new code and additive migration; a scoped activation approval must identify whether to enable only current trial2729/workflow8 (recommended first) or broader operation. Do not silently activate it from a code deployment.

Authorized rollout should preserve code/env/config checkpoints and a verified off-instance backup, run only migration2026_11_01_140000, refresh routes/Blade/FPM and gracefully refresh only the affected conversation worker. The process workers also need refresh if cached config activation changes. Apply the new flag without changing existing scope/ownership/budget/credentials. Verify actual SHA/hashes, authenticated panel/permissions, public HTTPS, new logs, stable payment ledger and one signal per original message. For the trial, old transfer screenshots are intentional test attachments and must never be recorded as real money. No replay of sends/generation is needed: a scoped full history reread can observe existing trial attachments once after approval.

Recovery: disable the new flag and preserve review/signal tables and audits; roll back only code/config through the runbook if required. Do not drop tables, replace the DB or undo real financial transactions. This feature never invokes the legacy approveProof handler that incorrectly assumes full payment and advances printing for this workflow.
