# General identity and payment-review activation

Owner approved production activation, then explicitly included new and existing confirmed orders. Applied1 November2026 at14:08:41UTC/16:08Cairo on codebe4a3c16d08b60f00538b1842e742d5369bec324.

## Reviewed scope

Workflow8/revision1, renamed توليد الهوية ومراجعة الدفع. All-new enrollment with order floor0 and event boundary10. New confirmed transitions on older orders may enroll, but pre-boundary events are not replayed. Legacy order-confirmation sender/after_order_id2683 retained. Trial manifests cleared. Existing trial runs1–3 preserved.

Reviewed26 checkouts containing confirmed rows:9 eligible/11 stories;15 product-only excluded;2 prior-trial/mixed-identity excluded. Exact representative/member IDs:2685,2691,2692,2697,2699,2704(members2704–2707),2708,2724,2728. Scope checked under locks with pinned input/definition hashes. Eligible checkouts were all new_confirmed, shipping not_ready, printing not_started, paid0, with usable original photos and no canonical/manual identity/previews/booklets. Each received one new idempotent owner-approved status event; no jobs or earlier events reset.

At14:19:34UTC all11 identity tasks6–16/attempts684–694 succeeded once. All9 preview sends completed once with wamid, runs4–12 waiting_response. Only one delivery receipt confirmed; null/unknown are not proof of delivery. No automatic payment recorded. Estimate0.041USD/image,0.451USD aggregate, not final billing. Per-checkout estimate ceiling1USD remains.

## Configuration and recovery

ORDER_PROCESS_ENABLED/IDENTITY_ENABLED/IDENTITY_TEMPLATE_READY/PAYMENT_REVIEW_ENABLED true; enrollment all_new, floor0, confirmation owner legacy; trial config null. Only five cached scope values changed and unrelated env values unchanged. Existing process/AI Supervisor autostart now true; same queues/one AI worker. Timeout110/stop180 for process, AI timeout240<lease270<retry300 with stop300. Four affected workers refreshed gracefully. No recurring AWS resource added.

Checkpoint /home/ubuntu/herokid-before-general-process-20261101 is private700, includes preactivation env/config/service definitions and cohort audit. Backup herokid-20261101T140149Z.sql.gz,42,863,459bytes; gzip/service/S3 successful. Maintenance14:08:40–14:08:44UTC. No tested DB restore claim. Preserve new attempts/messages/reviews on rollback; disable future actions through reviewed controls, never restore historical DB or retry uncertain provider results.

## Validation and follow-up

84 runtime/identity/payment-review tests554assertions on each isolated SQLite/MySQL; boundary/cohort test prevents historical replay and duplicate enrollment.60 builder/automation tests257assertions on each, including live/trial/paused page badges. PublicHTTPS200, scheduler/services healthy, new Laravel log errors0, historical failedjobs81. Follow-up view/controller correction displays general activation and permission-filtered real run count, using existing CSS assets; no migrations, worker/config changes or asset build required. Commit/deployment verification to be recorded after application.

Order ownership protection requested subsequently by owner is a separate proposed enhancement: existing fingerprint guard blocks stale automation work, but no complete employee-write exclusion exists. Do not describe a UI badge or process active_key as a full staff-operation lock.
