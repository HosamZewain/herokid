# Owner identity trial — 31 October 2026

## Authorization and release

Owner explicitly approved deployment, creation of one complete test order on the previously authorized recipient and walking the identity flow. This supersedes the earlier prohibition on a synthetic production order only for this exact trial. It does not authorize other customers, payment fabrication, shipping, printing, historical replay or uncertain AI retries.

Release ed028006b3079c2bfe91cc6bd3d82bfcc261cf99 deployed20:50:09–20:50:10 UTC, no maintenance. Base07cc0535673d59f8492d91adb1390f09573c6664; eight changed-file hashes matched, service/HTTPS gates passed. Original validation137 tests/736 assertions on isolated SQLite/MySQL. Source drift unchanged: bootstrap/cache/.gitignore and three retained hashed CSS assets. No migration/frontend/config change during code deployment.

## Recovery evidence

Checkpoint `/home/ubuntu/herokid-before-ed02800`, private0700, contains original source/env/config and hashes. Its trial subdirectory preserves pretrial environment/config, exact IDs and previous permission flags. Verified production backup `herokid-20261031T205022Z.sql.gz`:41,329,927bytes; gzip checked, off-instance S3 size and metadata SHA256 match `72b2e0bf35c3758fa81ede9bf735f7ef9d69d4cda68068deff52b62bf2fdfab9`. This was not a restore exercise. No database rollback or media deletion is permissible.

## Exact trial

- Order HK10-633/id2701, marker OWNER-IDENTITY-TRIAL-20261031-ed02800, created20:52:09 UTC through AdminOrderCreationService. Single story1, one item, unpaid489EGP including90delivery. Clear no-print/no-ship test labels.
- Two copies of the existing public demo character sheet uploaded through the standard private media path; both hashes verified. No customer photo reused. This validates transport/persistence, not identity likeness from independent source photos.
- Workflow8/revision1; canonical definition fingerprint525fdd2d98195cba30c159bcd674d90cec65214be94dc4493df57772d3a23ac9. Trial enrollment strictly pinned to order2701/workflow8, boundary2700; legacy confirmation owns new-order messaging. Other workflows remain disabled.
- Temporary activate/execute permissions granted only to owner1, with audit and original-state checkpoint. Not a permanent general permission change.
- Two scoped workers on existing EC2: order-process and order-process-ai, one process each, autostart=false, tries1. Default/conversation workers remain running; unrelated stopped workers unchanged. Scheduler healthy. Provider timeout180 < AI job240 < lease270 < Redis retry_after300. No new recurring AWS resource.
- Existing model gpt-image-2,1536x1024,medium, estimated0.041USD/image. One image authorized; configured estimate ceiling1USD is not an actual provider spending cap or receipt.

## Observed result and stop

Initial confirmation event22786 succeeded with one send and delivered receipt. The owner's actual reply was consumed and order became new_confirmed at20:54:36 UTC. Scheduler enrolled run1; task1/canonical attempt656 started20:55:08 and failed20:55:10 UTC. No output stored, no approval, no identity-preview message. Task is uncertain/identity_result_uncertain; run blocked/identity_requires_review; billing unknown/cost null. Failed-job baseline81 unchanged, zero other process runs. No payment/shipping/printing invoked.

Original error details were discarded by ProcessIdentityService; GptImageClient's warning was suppressed by production log level error. No provider request ID/error code was retained. It is not possible to prove the cause or billing from the short duration. Do not retroactively classify the attempt as unbilled.

Read-only diagnostics confirmed provider/model enabled, credential present, local extensions and both input images valid. Running the same image client with all HTTP faked passed without an external generation. A separate GET model-access check returned200 and the expected model; that proves model access only, not generation readiness, quota or billing.

## Diagnostic repair

A narrowly scoped follow-up preserves validated provider error code, request ID and HTTP status on future canonical attempts, without raw error text, response bodies, prompts or customer payloads. Existing uncertain/no-auto-retry behavior and billing classifications are unchanged. It cannot recover the missing diagnostics for attempt656.

Validation: identity suite36 tests/288 assertions on each isolated SQLite and MySQL. Tests cover provider429 receipts, no resubmission, rejecting malformed/overlong identifiers, and absence of private body/message text. PHP8.3 syntax checked.

## Pending acceptance

Identity generation, private preview, identity review WhatsApp delivery/correlation and customer approval remain unverified. Preserve the original attempt. Before any second potentially billable attempt, obtain explicit approval that acknowledges the first attempt's unknown billing, create a separate auditable canonical attempt, recheck inputs/scope and keep at most one new provider request. Never reset/replay the original attempt or repeat confirmation messaging. Stop at manual payment review; never invent a payment to complete the demo.

## Diagnostic release verified21:04 UTC

Follow-up9ff04df5eee62fb83652f4449983302e37b2d679 deployed21:02:59 UTC, no maintenance, five hashes matched. Checkpoint `/home/ubuntu/herokid-before-9ff04df`; only the two trial workers gracefully refreshed. Existing env/config remained byte-identical to the follow-up checkpoint; non-order_process config unchanged from pretrial. Authenticated owner page confirmed new_confirmed and delivered once. Scheduler successful; four required workers running.482 requests since original deployment:0 HTTP5xx,0 new Laravel log bytes/errors. Attempt656/run1 unchanged and blocked. A separate provider GET model-access check returned200, no second image request.

Prepared exact recovery script `/tmp/herokid-identity-diagnostics-release/prepare-second-attempt.php`, remote `/tmp/herokid-trial-second-attempt.php`: default read-only check passed, no writes. Execution is pending a new owner decision. It preserves canonical attempt656, creates a separate attempt with recovery provenance, archives the original task state and advances only run1/task1 after scope/input/history guards. Never execute its approval flag without the new decision. Estimated0.041USD is not a billing guarantee; first attempt remains unknown. Temporary trial grants/workers/config remain scoped as above while awaiting the decision.

## Second attempt explicitly approved and observed —1 November2026

Owner paused pending account review, then funded OpenAI and explicitly approved one additional attempt. Executed09:51:02 UTC after live SHA/file hash/scope/input/worker checks and private row backups at `/home/ubuntu/herokid-before-owner-second-2701`. Original attempt656 SQL dump compared equal before/after; no original receipt or billing rewrite. New attempt657 started09:51:05, succeeded09:51:36, estimated0.041USD (not an actual provider bill). Output private S3 PNG1536x1024,2,255,935bytes, stored checksum verified. Visually reviewed demo character sheet, not a real-child likeness acceptance test.

Signed preview and image both200 with identical output bytes; tampered signature403. Scheduler sent one preview09:52:09, then waited for the real owner response. Delivery confirmed; response consumed once, canonical approval09:53:43 via robodesk_process. Order and identity request both link657. Run waiting_staff at review, paid0, one response/approval event/preview send; queues empty, failedjobs81, no other process runs/workflows. Authenticated order UI shows linked approved identity and both messages delivered. No shipping, printing, payment or scene generation.180 bounded HTTP requests after retry:zero5xx and zero ERROR-level Laravel entries.

Owner screenshot exposed RoboDesk generic AI clarification despite valid confirmation; see separate handoff. Business status remains new_confirmed under the deployed action. Owner requested moving it to existing costomer_confirmed status after approval. Prepared patch updates that state plus history/outbox/context atomically, preserving ledger and manual payment gate; simulation reflects it and inactive/nonstandard target blocks execution. Subsequently deployed as ae52dcb; no existing-order status backfill. Temporary trial authority/config remains bounded for review; broader activation remains unapproved.

Prepared status-repair validation:99 related identity/runtime/builder tests /566 assertions passed on SQLite before adding two final preview/context assertions; the amended approval regression passed separately. Final identity suite on isolated MySQL:37 tests/304 assertions. PHP8.3 syntax passed. Tests cover per-story status history once, correlated response deduplication, signed preview surviving the state change, unchanged deposit ledger, disabled target rollback and matching simulator status. No live generation/message/status action during these tests.

## Status repair deployed — 1 November 2026

Owner approved deployment and requested waiting for RoboDesk repair before another trial. Release ae52dcbdf50a56f4ec3c454fa3f065df4e0f1e26 deployed10:06:27–10:06:31 UTC without maintenance. Eight file hashes matched, env/cached config unchanged, only the two process workers gracefully refreshed. Authenticated editor action and public HTTPS verified; services/scheduler healthy.139 bounded requests:zero5xx/new Laravel log bytes. Recovery checkpoint `/home/ubuntu/herokid-before-ae52dcb`, base9ff04df. No schema/assets/dependency changes.

Trial snapshot unchanged before/after: one approval/response/preview send, run waiting_staff/review, paid0 and process queues empty. HK10-633 retains its pre-fix business status; future approvals advance status, but no historical replay or status backfill was executed. No new generation/message/payment test. RoboDesk reply correction and a subsequent explicitly scoped trial remain outstanding.
