# HeroKid order confirmation — polling contract, 31 October 2026

**Prepared, not deployed or enabled. Supersedes the earlier callback/HMAC proposal entirely.** Technical evidence below is the owner's relayed RoboDesk code inspection, not a live send verified by HeroKid. RoboDesk's agent subsequently confirmed the three message fields live on production using real-number read-only checks; HeroKid has not made a real template send. The first populated templateName for this template remains to be observed during the approved pilot. No HeroKid-specific RoboDesk code, callback, shared HMAC secret or AI interception is required.

## Scope

One confirmation per checkout, all its currently `new` parts move together to `new_confirmed`. Labels: **طلب جديد غير مؤكد → طلب جديد مؤكد**. Deleted/cancelled/advanced orders or changed customer phones cannot be confirmed. No payments, generation, shipping, acknowledgement message or other automation is triggered. RoboDesk's normal AI acknowledgement is not evidence that HeroKid has already updated the order.

## Send sequence

Use existing HeroKid encrypted conversation-user credentials and the existing Authorization algorithm. Trusted base `https://hero-kid.robodesk.ai/api/conversation`; no redirects or credential logging.

1. `GET /messagesByPhone?phone=2010XXXXXXXX&channel=WhatsApp`. Verify returned phone. Read `interactions[].id` and boolean `closed`; `_id` is NOT the lookup field.
2. Close open interactions (including agent/AI conversations, as the owner specified): `POST /close`:
   ```json
   {"closeData":{"conversations":[{"_id":"synthetic-open-id"}],"isBulk":true},"note":null}
   ```
   Require successful HTTP and literal boolean `success:true`. Failed/uncertain close stops the sequence; do not send afterwards.
3. `POST /start/sendMsg`:
   ```json
   {
     "channel":"WhatsApp",
     "to":"2010XXXXXXXX",
     "language":"ar-EG",
     "templateMessage":"تأكيد طلب HeroKid HK10-22",
     "template":{
       "templateName":"herokid_order_confirmation",
       "text":"$1 $2 $3 $4",
       "data":["اسم العميل","HK10-22","قصة × 1 · كتاب تلوين × 2","450.00"]
     }
   }
   ```
   Do not send `procedureId`. Meta template language is `ar`, but RoboDesk needs `ar-EG`. Approved template ID `1089097637507714`, UTILITY, four positional variables: customer, exact short order reference, product/story details with quantities, numeric after-discount total INCLUDING shipping. The template supplies currency/shipping wording itself.
4. Retain only response top-level `_id` as `interaction_id`, `channelId` as `message_id`. Both must be present; wamid begins `wamid.`. Acceptance is not delivery.
5. Exact HTTP 501 plain text `Customer already have another interaction`: known no-send race, repeat lookup/close/send once. Second identical 501 is blocked. HTTP 409: disabled template, blocked. Any other error/timeout/invalid receipt: uncertain, no automatic retry. No provider idempotency exists. Never retry using absence of matching text in a limited history window as proof of no send.

A durable compare-and-set claims the event before even closing a conversation; duplicate delivery cannot repeat an attempted event. Same-phone send sequences and history sync share the existing contact lock. At most six 15-second HTTP calls, job timeout 110 seconds, contact lock 130 seconds; reservation retry must exceed these (existing documented Redis retry 960 seconds, reverify on release). Lock contention before a claim only redispatches unattempted work. Worker crashes leave processing evidence requiring review, never automatic resend.

## Polling and correlation

Reuse the existing conversation refresh queue/scheduler and `GET /messagesByPhone` with its saved `after` cursor. All customer data remains local to existing protected storage. Metadata is encrypted alongside existing encrypted messages and omitted from UI/API serialization.

New generic message fields, with explicit `null` when unavailable:

- `channelId`: this message's wamid.
- `replyTo`: original template wamid for a button/reply.
- `templateName`: name on an outbound template message.

Existing fields used: `id`, `interactionId`, `direction`, `text`, `date`, `channel`, `attachments`. `date` must be an unambiguous ISO-8601 instant with offset/Z, preserving source milliseconds. Missing new fields are NOT treated as explicit null: old responses cannot silently enable typed matching.

A candidate is inbound `direction:in`, trimmed text exactly `تأكيد الطلب`:

- Non-null `replyTo`: match exactly one successfully sent HeroKid request by its original wamid and phone. Different inbound interaction is allowed. If replyTo does not match a stored sent template for this phone, treat it as typed confirmation and apply the interaction rule below. Ordinary quoted messages can also populate replyTo; outbound AI/flow replies must never be treated as customer confirmations.
- Null or non-template `replyTo`: same interaction, latest actual `herokid_order_confirmation` template strictly before the inbound message. Use the saved outbound message's wamid and timestamp, then match the known send receipt and phone. Do not use mutable conversation variable `2`, latest order for a phone, or local HTTP receipt time as the template's send time.
- Typed matching waits for outbound template evidence in history. If that message is outside the provider history window, absent, untracked, or tied for latest timestamp, hold for reconciliation; never guess or fall back to an earlier order. This stricter evidence rule prevents cross-order confirmation.
- Saved messages are reconsidered on subsequent empty delta pages, so an inbound message read just before receipt persistence is not lost. Cursor/history/confirmation changes commit in the same DB transaction. Invalid metadata cannot advance the cursor.
- Original confirmation timestamp/logs are idempotent. No regression after cancellation, deletion, contact change or later order progress. History is preserved; no customer messages or old events are deleted.

Current normal polling cadence remains **10 minutes per active phone plus scheduler/queue/backoff delays**, with the existing global ceiling of 20 requests/minute. This is not instantaneous confirmation or a guaranteed latency. No extra timer/resource or parallel poll loop. Existing history refresh permissions remain. No callback endpoint is added; legacy `order.confirmed` webhook events are rejected.

## Deployment and activation gates

No current authorization to deploy or send. Baseline AWS SHA reverified `46f8e3e260dc6319845e1f309218340837178d70`.

1. Field rollout is reported confirmed by the RoboDesk agent in the owner's latest update. Preserve that evidence; still verify actual outbound templateName/wamid/interaction/time during the approved pilot and clarify cursor completeness before broad activation. Local mocks alone do not prove provider behavior.
2. Revalidate live code/drift, private recovery checkpoint and fresh off-instance backup. Review exact release diff. Apply only the status-definition migration and encrypted-message-metadata migration by explicit path; no unrelated pending migrations or history backfill. Additive down methods retain referenced evidence.
3. Keep legacy `ROBODESK_ENABLED=false`; no inbound/outbound secret needed. Existing encrypted conversation settings remain unchanged. New switches default disabled, contract false, audience trial with empty allowlist.
4. After owner approval for a defined pilot, use `ROBODESK_CONFIRMATION_AUDIENCE=trial` and `ROBODESK_CONFIRMATION_TRIAL_PHONE_HASHES=<comma-separated SHA256 of canonical +E164 approved phone(s)>`. Provision securely, never put actual numbers in reports. Empty/malformed allowlist blocks readiness. Capture a fresh `MAX(orders.id)` INCLUDING deleted rows as `ROBODESK_CONFIRMATION_AFTER_ORDER_ID`. Set enabled/contract flags only after those gates pass. The pilot authorizes closing any open conversation for its approved number and sending one new-order template; no synthetic production order should be created without explicit authorization.
5. `ROBODESK_CONFIRMATION_AUDIENCE=all_new` requires separate explicit broad activation approval. Creation-time eligibility and the fresh boundary prevent replay of old held events and checkouts. Orders created while disabled/outside the pilot stay held permanently unless separately reviewed; no catch-up sweep.
6. Rebuild relevant config/routes/Blade caches narrowly; gracefully refresh only default and conversation workers after checking in-flight work and timeouts. Preserve stopped order automation/AI workers. Revalidate scheduler, protected encrypted data, exact SHA/hashes, HTTPS, UI and new logs.
7. Observe HTTP receipt, actual matching polling evidence and the DB status transition separately. Do not call AI acknowledgement or provider acceptance proof of completion. No paid synthetic send needed for local validation.
8. Kill switch: disable `ROBODESK_CONFIRMATION_ENABLED`, refresh cached config/affected workers gracefully; both sending and confirmation processing stop, ordinary conversation history continues. In-flight actions may finish. Retain all messages, requests, new statuses and metadata. Review unknown/processing sends with RoboDesk; never blind retry. Code rollback must preserve DB evidence and new status references, not restore an old database.

Zero new recurring AWS infrastructure. Existing Meta/RoboDesk template usage charges still apply when activated; encrypted metadata occupies existing DB/backup storage.
