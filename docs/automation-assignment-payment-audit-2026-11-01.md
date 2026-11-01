# Automation ownership, payment review and order audit — 1 November 2026

Status: **prepared, not deployed**. Isolated branch `codex/automation-assignee-payment-review`, based on documentation commit `ef5a504`; production was independently verified at `db66e184d5d03c6e62bbae4009c360ba867211b7` at 15:06:35 UTC. The main desktop checkout's unrelated work is untouched.

## Resulting behavior

- The existing order assignee column shows **Automation** while eligible order confirmation, process enrollment, execution, identity generation, response waiting or uncertain execution owns the checkout. A dedicated Automation filter is available. Employee assignments are preserved underneath; no fake employee or separate execution-owner field is created. Shipping credit continues to use the actual employee assignment.
- Confirmed identity waits under Automation until an attachment-review signal exists or a real payment has already been recorded. Revision requests/timeouts and safe blocked/completed/stopped runs return to the team. A running/uncertain identity or uncertain send retains its reservation until reconciled; stopping the entire workflow is not equivalent to stopping an individual execution.
- Admin order mutations, acquisition/takeover, merge, bulk mutations, production/identity upload and Agent API acquisition check ownership. Internal notes/tags, financial review and permission-checked message retries remain available. The short interval between status entry and process enrollment is reserved while the enabled workflow evaluates the event.
- Inbound attachments after the exact identity-preview template, in the same verified conversation, open human review. A zero-balance unpaid checkout becomes **بانتظار مراجعة الدفع** (`pending_review`, unpaid accounting behavior). Existing deposits and all recorded money remain unchanged. The payment ledger records a zero-value status transition excluded from collections. Staff still records actual money manually after reviewing RoboDesk.
- Already-open eligible reviews adopt the new display status on a subsequent normal full read using the existing correlated signal. No historical job replay, duplicate signal, receipt copying, message resend, AI generation or invented payment is involved. Closing a review without a recorded payment returns pending_review to unpaid.
- Order activity records Automation as actor for automated lifecycle effects, even if an admin initiated the history refresh. Entries cover confirmation attempts, conversation closure, provider acceptance, delivery changes, workflow steps, generation per story/child, customer responses, identity approval/status changes, attachment review, and handoff. Human stop/continue/retry/review actions retain the actual staff actor. Poll scheduling and unchanged reads do not create duplicate activity.
- Acceptance by RoboDesk is distinct from delivery. Failed or uncertain outcomes are labelled explicitly; no uncertain send/generation is automatically retried. No raw provider payload, token, receipt image or generated file is copied into activity properties.

## Review boundaries

The existing full-history correlation, phone/template/interaction checks, one-time response consumption, multi-story identity fingerprints and payment isolation remain in place. Tests cover three stories/two children plus deferred product work, payment before or after identity approval, duplicate reads, changed inputs, delivery failures and guarded retries.

Guards protect the normal application entry points; this is not a distributed lock over arbitrary SQL or an admin request already in flight before enrollment. Existing runtime context checks remain necessary. This release adds future activity records; it does not fabricate missing historical activity. Pending reviews with real deposits retain their financial status and the existing review panel.

## Validation

- Broad isolated regression: 348 tests / 2,715 assertions passed on SQLite and MySQL, including runtime, identity, builder, confirmation, Agent API, assignment, payments, timeline, merge/bulk/full edit, operational statistics, shipping/payment reports and conversation refresh.
- Final revised runtime/identity/payment/Agent API/timeline subset: 144 tests / 1,082 assertions passed on each database, including 3 additional tests for existing-review adoption and preservation/manual recording of money.
- Confirmation/assignment final subset: 49 tests / 263 assertions passed on each database.
- PHP 8.3 syntax, `git diff --check`, committed manifest and existing badge CSS checked. No asset source or dependency changes; committed build assets are preserved.
- All execution tests use synthetic isolated databases with external requests faked/blocked. No production tests, new orders, customer messages, AI calls or financial writes were performed.

Read-only production snapshot: 13 runs (6 waiting_response, 7 waiting_staff), 7 pending payment reviews, 6 without recorded money; 81 historical failed jobs unchanged. These counts are a timestamped snapshot, not a permanent operational state.

## Deployment and recovery plan

Requires scoped deployment authorization under AGENTS.md. Revalidate live SHA, drift, queues and current worker activity; preserve current production env/config and an exact private recovery checkpoint. Apply only `2026_11_01_170000_add_pending_payment_review_status.php`, an additive status-definition migration. Do not rerun other migrations, reset jobs, expand enrollment or change resource/service configuration.

Refresh route/view caches, check served-file permissions, reload FPM, and gracefully refresh the affected existing confirmation, conversation and process/AI workers without interrupting in-flight generation. Verify exact release SHA/hashes, authenticated order assignee/activity/payment UI, HTTPS, scheduler and bounded new errors. Existing open-review status adoption is normal future polling, not a forced replay.

Rollback code only after checking current effects; preserve the additive status definition, audit rows, payment events, reviews and all current process settings. Migration down intentionally does not delete the referenced financial status. Older code must be checked for compatibility with pending_review before rollback; never restore an older database or blindly restore pre-activation configuration.
