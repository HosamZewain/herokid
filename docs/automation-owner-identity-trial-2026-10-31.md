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
