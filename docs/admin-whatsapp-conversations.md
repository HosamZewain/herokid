# Order WhatsApp history (RoboDesk)

## Admin setup

Open **التكاملات → إعدادات محادثات واتساب** (`/admin/robodesk/conversation-settings`).
Requires `robodesk.manage`. Enter the dedicated RoboDesk integration user's email/password, choose 1–50 recent conversations (default 20), and enable reading.
The password input is always empty on subsequent visits; leaving it empty preserves the saved password. Disabling integration preserves the history. Email/password use Laravel encrypted casts in a separate settings table and are excluded from JSON and credential-save audit payloads. Preserve `APP_KEY` when migrating/backup-restoring. Do not put credentials in Git, browser JS, generated files or logs.

Message viewing requires an active admin account with **both** `orders.view` and `orders.conversations.view`. The migration grants the latter only to existing direct access managers; grant it explicitly to appropriate employees using normal role/permission management. New customer-service role defaults include it; existing custom roles are not reset.

## API and synchronization

Server-only HTTPS request to the fixed RoboDesk host:
`GET https://hero-kid.robodesk.ai/api/conversation/messagesByPhone`.
Always sends digits-only international `phone`, `channel=WhatsApp`, and configured `limit`. Stored Egyptian local mobiles normalize using the existing Egyptian phone convention; other international numbers retain their country code. Invalid numbers stop locally. No name/gender inference, no browser-supplied phone.

Authorization is the supplied legacy base64 format, **not** Bearer. Password hashing reproduces JavaScript signed 32-bit arithmetic and UTF-16 code units. It is temporary pending a dedicated integration key; the adapter isolates future auth changes. Existing events/webhook/payment integration remains unchanged.

- Panel opens: read the DB cache immediately, then asynchronously request a full sync.
- Visible, expanded panel: background sync every 15 seconds using encrypted stored `after` (remote newest-message ID).
- Empty delta preserves the cursor; messages repeated at the cursor are deduplicated by remote ID.
- Live RoboDesk can also return `direction=system` / `type=systemLog`, contrary to its initial contract. Only this exact pair is excluded from display/storage; other malformed or unknown records still reject the entire page atomically. The cursor uses the last raw record ID, including a filtered log, so a log-only delta does not repeat forever. IDs remain validated and encrypted; no system-log text or attachments are persisted.
- Provider 400 with `after`: retry once without it; reset the cursor even if the fallback is empty.
- `senderType` is opaque, inert provider metadata, preserved verbatim up to 64 characters (or null); it does not grant permissions or control execution. The API validator and persistent column share this bound. Longer/non-string values reject the page atomically without advancing its cursor.
- Opening again/manual Refresh requests full recent history, including delivery/text edits. Incremental responses cannot detect edits to older messages not returned by RoboDesk.
- Closing/minimizing stops polling and aborts pending browser requests; background tabs do not poll. Normal navigation closes windows; private messages are not persisted in browser storage.
- Windows anchor at the bottom LEFT and extend rightward; desktop space excludes the right admin sidebar. Individual conversation content stays RTL.
- Per-contact 30-second distributed lock and 12-second successful-sync cooldown avoid concurrent or duplicate polling. Slow/failing calls have bounded timeouts (3-second connect, 8-second total per attempt; invalid cursor adds at most one attempt).

Admin endpoints:
`GET /admin/orders/{order}/whatsapp-conversation` reads cache only, never creates history or calls the provider.
`POST /admin/orders/{order}/whatsapp-conversation/sync` fetches and atomically persists validated responses. CSRF, authentication, permission checks and per-user throttle apply. Optional `full=1` drops `after` for that call. A `before` local message ID pages older **saved** history (50 per page); this is not a RoboDesk paging parameter. Cross-contact cursors cannot expose another contact's messages. No mutation of assignments, order status, payments, items, production files or shipping.

Signed attachment URLs exist only in the live HTTP response/browser memory; never in DB, cache, audit or logs. Persistent attachment metadata contains the supplied filename only. Image links can render inline, other files open by an HTTPS link. Expired or unsigned attachments require manual full refresh; old attachments outside RoboDesk's recent-conversation limit may no longer be available remotely. Messages are plain text (`textContent`), not executable HTML. No arbitrary host/URL settings or server-side attachment fetching.

Messages/phone/cursor/sender name/attachment metadata are encrypted at rest. Stable ID hashes support indexed uniqueness and chronological pagination. Provider responses are fully validated before the transactional write; failures preserve old history/cursor. No delete-on-sync, no retention expiry; a message falling outside a recent API window does not remove it from HeroKid. Source interactions/private notes/system logs are not requested separately or fabricated. Multiple orders with the same normalized primary phone share one conversation; different phones remain isolated.

## Release / verification

Apply `2026_10_09_000100_create_whatsapp_conversation_history` using the normal additive migration process. Includes three new tables and one permission; no changes to order schemas. Rebuild/ship Vite assets. No required new environment variables; optional `ROBODESK_CONVERSATIONS_ACCOUNT_KEY` defaults to `primary` and namespaces a single business account. Changing it isolates future cache lookups.

Run the conversation feature tests, existing RoboDesk tests, full Laravel suite, JS helper tests and Pint. Live verification must be done after adding real credentials in Admin: open an authorized synthetic/order conversation, check inbound/outbound messages and attachment links, wait for a delta poll, close the panel and confirm polling stops. Local mocks/UI fixtures do **not** prove live authentication/provider compatibility. Never log the Authorization header.

### 2026-10-09 system-log compatibility regression

Sanitized production diagnostics for HK10-105 confirmed HTTP 200, a matching phone and 33 records: four `system / systemLog`, 25 text messages, one image and three reactions. The four logs previously caused atomic rejection of the entire response. A synthetic fixture now verifies that all 29 actual messages survive without storing or exposing log bodies, while log-only deltas advance the cursor. Unknown directions, mismatched system-log types, unsafe IDs and cross-phone pages still fail safely.

Verification: focused conversation/RoboDesk tests 34 passed (219 assertions), JS helpers 4 passed, changed PHP Pint and whitespace checks passed. Full-suite first run: 1242 passed and one existing Bosta queue representative-link assertion failed; isolated Bosta suite: 20 passed (170 assertions). Full-suite rerun: 1243 passed (10000 assertions). Bosta code was not changed. No new migrations, environment variables or frontend build are required for this compatibility patch. Repair of the live order remains unverified until the patch is deployed and the conversation is refreshed.

### 2026-10-09 sender metadata compatibility regression

Subsequent sanitized diagnostics confirmed the system-log patch was deployed, but actual messages still failed because their `senderType` contained 21 characters while validation and the database column allowed only 20. The follow-up accepts up to 64 characters without truncation or mapping. The synthetic 33-record fixture includes 21-character outbound sender metadata; additional tests cover the 64-character boundary, nulls, malformed/oversized metadata, atomic history/cursor preservation, and migration data preservation.

Deployment **must run `php artisan migrate --force`** to apply `2026_10_09_000200_widen_whatsapp_message_sender_type` before traffic resumes. It widens one nullable column without rewriting message data. Rollback refuses to shrink the column if existing values exceed 20 characters; do not delete messages to force a rollback. No new environment settings, credentials, dependencies or frontend build are required. The outbound integration's `ROBODESK_ENABLED` flag remains independent and must not be enabled to repair read-only history. Live repair is unverified until deployment and a successful refresh.

Verification: the new 21/64-character fixtures reproduced HTTP 502 before the repair. Afterward, focused conversation/RoboDesk/migration tests passed on both MySQL 8.4 and MariaDB 10.11: 37 tests, 248 assertions on each. Full `composer test`: 1246 passed, 10029 assertions. JS helpers: 4 passed. PHP 8.2 syntax checks, changed-file Pint and whitespace checks passed. No production deployment or successful live sync is claimed by these local results.
