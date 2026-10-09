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
- Provider 400 with `after`: retry once without it; reset the cursor even if the fallback is empty.
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
