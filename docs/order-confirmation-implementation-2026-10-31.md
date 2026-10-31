# Order confirmation implementation — 31 October 2026

Status: **prepared locally; not deployed, no real sends, no production writes or settings changed**. Branch `codex/order-confirmation`, based on AWS `46f8e3e260dc6319845e1f309218340837178d70` (reverified by SSH). Primary dirty workspace preserved. Earlier deployment approval applied to operational cards, not this integration change.

Implemented:

- Global `new` display label becomes طلب جديد غير مؤكد; adds `new_confirmed` / طلب جديد مؤكد. One explicit reference-data migration; no order backfill or historical status-log rewrite. Down migration deliberately preserves referenced definitions.
- Practical confirmation section at `/admin/order-automation`, including pending/held/blocked/uncertain/provider-accepted counts, customer confirmations and latest attempts. Existing AI workflow editor remains collapsed; not activated. Existing view/order permissions retained.
- One outbox event per checkout and after-commit dispatch to the existing asynchronous queue. Explicit enable, contract-ready flag and activation order-id boundary; eligibility captured at creation. Old held events cannot be replayed by activation or generic retry. Added parts of pre-activation checkouts cannot trigger sending.
- Actual RoboDesk template-send adapter uses existing encrypted conversation credentials, fixed trusted host, ar-EG, positional parameters and after-discount total including delivery. No procedureId, no non-existent events API for this path. Only receipt channelId/_id retained from response. Ordinary conversation reading/replies remain unchanged.
- Durable atomic send claim and one attempt. No provider idempotency exists: crash/timeout/ambiguous response becomes review-required without blind retry. Known open-interaction 501 or disabled-template 409 is blocked. Provider error bodies/auth/customer response fields are not logged. A network result is not a WhatsApp delivery guarantee.
- Signed `POST /api/integrations/robodesk/v1/order-confirmations`. Original template wamid + interaction id + exact short reference + actual sender must match the stored send receipt/current phone. Callback UUID is idempotent; receipt/order changes commit atomically. Checkout row locks protect simultaneous confirmation; no regression of cancelled/deleted/advanced orders; repeat clicks preserve first timestamp/logs. No payment/shipping/AI side effects.
- Confirmation-only mode fences legacy action endpoints and non-confirmation outbound messages, including the historical pending foundation events. Existing GET checkout data is not exposed with the confirmation-only credential.

Validation:

- **89 tests / 766 assertions passed on SQLite and isolated MySQL 8.4**. Runtime PHP 8.5 test image; PHP 8.3 compatibility lint separately.
- Focused tests cover grouped checkouts, one-send claim/redelivery, sender/template/interaction mismatch, missing/bad/expired HMAC, flat callback body signing, caller cannot choose target status, duplicate UUID/content conflict, repeated new UUIDs, late cancelled/deleted/advanced order clicks, changed phone, initial activation/old-checkout/disabled/contract/sync-queue guards, correct quantities/discount/shipping template amount, timeout/missing receipt, 501/409, no other workflows, status definitions and UI permission boundaries.
- Existing order automation, RoboDesk integration, dashboard, status settings, operational-card and filter regression tests included. Synthetic HTTP and queues only; no real provider calls. These passes do not prove RoboDesk's unfinished callback or open-conversation send implementation.
- Vite production build passed, preexisting chunk-size warning retained; manifest unchanged and previous hashed assets preserved. Local synthetic rendered UI inspected. No production customer snapshots used.
- Read-only production inspection: inbound integration disabled and both legacy event secrets absent; Redis selected; order automation disabled. Historical held counts: 1,961 confirmation events, 733 preview events, 19,612 workflow events. Counts are dated observations, not a cleanup/replay authorization.

External activation blockers:

1. RoboDesk start/sendMsg cannot send when the customer already has an open WhatsApp conversation. RoboDesk agent must implement/support that case and provide the actual API contract; never close/recreate customer conversations as a workaround.
2. RoboDesk must intercept the actual quick reply before AI, bind it to the original message's template variables (not mutable conversation variable 2), and send the signed callback with bounded retry/durable receipt.
3. Provision dedicated HMAC inbound secret securely. Final synthetic contract validation, then owner approval for production deployment/activation and any controlled live pilot. Do not enable contract_ready from this local test result alone.

Handoff: [Arabic request for RoboDesk agent](robodesk-agent-phase-one-request.md), [complete wire protocol and rollout gates](robodesk-order-confirmation-contract-2026-10-31.md).

Private local evidence `/tmp/herokid-confirmation-evidence`; code `/tmp/herokid-order-confirmation`. No new recurring AWS infrastructure. Existing WhatsApp/Meta metered message charges still apply when eventually activated.
