# Confirmation close recovery and Tags wrapping — 31 October 2026

Owner authorized deploying the wrapping change and continuing the approved one-order pilot. Base AWS SHA `157e8faea212506f48f0fef99eda0f46f3dbf7a8` was verified unchanged. This candidate combines wrapped compact tags with explicit completed closure status and one close-only recovery following a fresh phone-validated history read. A second close failure stops; sendMsg uncertainty never automatically retries. Eight possible 12-second calls stay below job 110s/contact-lock 130s/retry 960s.

Validation before deploy: 42 tests / 444 assertions on each isolated SQLite/MySQL, PHP 8.3 transport lint, previous wrap build unchanged. Synthetic browser showed no horizontal overflow and unchanged 38px chips at desktop/mobile widths.

Pilot recovery must preserve attempt one. Recorded evidence proves the original attempt stopped at close HTTP500 before calling sendMsg, and a read-only follow-up found an open conversation with zero confirmation templates. RoboDesk reports a completed rollout as a likely cause, but lost pod logs mean the exact cause is unproven. Owner-directed recovery applies only to HK10-614 / order2682 / its existing event and approved phone, not general unknown/historical jobs. Private operator runner keeps an exclusive lock, checks exact prior sanitized evidence and production-disabled configuration, snapshots the original event privately, then atomically claims unknown/attempt1 as processing/attempt2. It uses the actual deployed transport and never resets attempts, creates another order or enables global sending. Unknown results remain unrepeatable automatically.

Live deployment and pilot results will be recorded after verification. Global all-customer activation is outside this trial.

## Deployed and verified

Full live SHA **e55dcbd4eb1a67fd6ca2dad84cf6618dd30ba263**, deployed 31 October **11:50:39–11:50:43 UTC / 13:50 Cairo**. Eight file hashes matched; .env and cached config hashes unchanged. Private checkpoint `/home/ubuntu/herokid-close-before-20261031`; code rollback base `157e8faea212506f48f0fef99eda0f46f3dbf7a8`. No schema change or maintenance. FPM graceful reload, Blade cache, graceful default-worker stop/start; conversation worker and scheduler not restarted. Public home/up/cart/CSS 200. First bounded window: 96 requests, no 5xx/Laravel errors, failed jobs unchanged at81. Default/conversation waiting/reserved zero; delayed conversation jobs normal.

Authenticated production browser verified 15 Tags wrapping over two rows with no horizontal overflow; individual Tag styling unchanged. Screenshot `/tmp/herokid-close-release/wrapped-tags-live.png`.

## Approved one-order pilot succeeded

Existing order **HK10-614 (2682)** only. Attempt1 was preserved as a pre-send close failure; reviewed recovery attempt2 returned **history200, close200, sendMsg200**, with both original template wamid and interaction receipt. Exactly one sendMsg was made across both operator attempts. No general retry/reset/replay was introduced. Owner confirmed receipt and pressed the button.

At **11:52:10 UTC / 13:52:10 Cairo**, the actual deployed polling service changed the order to **new_confirmed**. Persisted evidence: exactly one outbound template with correct templateName, wamid equal to send receipt, derived interaction equal to receipt; one inbound original-template reply, one consumed confirmation message, one status log, workflow confirmed via reply. No review flag. Paid amount0, Bosta shipments0, automation runs0.

A second full-window reread at11:52:50 UTC returned167 messages and left confirmed_at unchanged at11:52:10, exactly one consumed message and one confirmation log. No resend. This demonstrates idempotence for the approved real pilot; synthetic tests cover typed confirmation and other edge cases. Actual background cadence for automatic confirmation was not exercised: the pilot used explicit runs of the same production sync service.

The trial record remains clearly labelled not for shipping/production/collection. Keep the record/evidence; no cleanup or deletion authorized. Private runner `/var/tmp/herokid-confirmation-pilot-recovery-20261031.php`, private state `/var/lib/herokid-confirmation-pilot-20261031`. Recovery mode is now locked out by succeeded/attempt2 and exclusive snapshot guards.

## Historical activation plan — now completed

This section records the pre-activation state. Owner subsequently approved activation and historical held-event cleanup; see [completed result](confirmation-activation-and-cleanup-2026-10-31.md). Before activation, global configuration was confirmation_enabled=false, contract_ready=false, audience=trial, empty trial allowlist; legacy integration false, confirmation_only true. No ordinary customer has received a template through activation. Future-all-orders activation is separate from the completed one-number pilot and subsequently received owner approval.

Concrete activation procedure after approval: revalidate exact release and health; privately checkpoint .env/cached config; set ROBODESK_CONFIRMATION_ENABLED=true, ROBODESK_CONFIRMATION_CONTRACT_READY=true, ROBODESK_CONFIRMATION_AUDIENCE=all_new, ROBODESK_CONFIRMATION_ONLY=true; capture MAX(orders.id), including deleted rows, at activation as ROBODESK_CONFIRMATION_AFTER_ORDER_ID. Keep ROBODESK_ENABLED=false. Rebuild restricted config cache and gracefully refresh only default/conversation workers. Verify new config without printing secrets and observe future real events read-only. Never release held/historical events; no synthetic extra orders or paid test actions. Kill switch disables confirmation and refreshes affected workers/config while preserving all event/order evidence. New customer confirmations normally arrive on existing ten-minute conversation refresh cadence plus queue delays.
