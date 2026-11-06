# Storefront sales assistant — prepared, not deployed

Implementation worktree `/tmp/herokid-sales-assistant-20261106`, branch `codex/sales-assistant-20261106`, based on revalidated AWS/Main `e388684829c3e3bfe75d7cb1ce7e411321a659c7`. The primary checkout's unrelated changes were preserved. This document describes prepared application code, not a live release. The new feature defaults to disabled; no production worker, migration, feature switch or customer order was changed.

## Customer experience

A once-only invitation and right-hand floating button open an accessible light Arabic chat. The left-hand WhatsApp button remains available. `/shopping-assistant` uses the same server-owned conversation and native cart. Recommendations are hydrated from published catalog facts; images, prices, URLs and availability are server supplied. At most three recommendation cards, or two optional upsells, appear per reply. A durable decline suppresses further model and server upsells. Editable public keywords improve Arabic topic retrieval.

Selection opens the existing story/product/package personalization forms inside a same-origin panel. Their native variant, language, multi-child, upload, pricing, discount and Bosta-address behavior is reused. The purchase panel keeps child inputs separate from chat. Back navigation retains the cart; an owned cart editor uses current personalization schemas to edit child values and retain/replace specific photos. It never implicitly copies another child's photos. Linked products use explicit linked-story references. Existing identity-based carts remain under their original identity controls.

Embedded checkout first displays a private server-rendered review with children, photos, quantities, address, shipping, discount and total. Only the subsequent explicit confirmation executes the existing checkout transaction. The encrypted 15-minute quote binds session, conversation, cart, address, discount and shipping. Current prices, availability, product fields, photo ownership and existence are checked again. Native checkout-submission receipts provide exactly-once creation and prevent duplicate notifications/conversion events. No payment or production approval is inferred. Previous-order cancellation is unavailable in the assistant; existing shipping-merge rules are reused.

WhatsApp opens a customer-controlled link, with an optional preview summary. The website does not send that message. No tools can manage historical orders.

## Model, private data and tool boundary

- Only `gpt-6-luna`, Responses API, `reasoning.effort=low`, `store=false`, no fallback, no previous-response storage, no general browser/code tools.
- Separate `/mcp/storefront-sales`; internal `/mcp/herokid` and the administration/production assistant are unchanged. Tools: `search_catalog`, `get_catalog_item`, `get_purchase_requirements`, `get_store_policies`, `get_my_cart_summary`.
- Server-only encrypted capability expires after 180 seconds, binds a single processing turn and safe cart summary, and is revoked by terminal state. Each turn permits at most four successful tool invocations. No administrative token or customer-session selector is accepted.
- The model sees redacted need text, bounded recent redacted history, public catalog facts and cart keys/quantities/completeness booleans. Child images, upload URLs/paths, phone and address form values stay in native private storage. Redaction covers known cart names and common contact/address/URL patterns; it is not a guarantee that every unlabeled personal detail typed into free text can be detected. The interface directs customers to use private forms.
- User and catalog text are treated as data. Structured outputs are validated; unknown catalog keys are discarded and model text is rendered with `textContent`, never HTML. UI write actions remain explicit session/CSRF-protected requests.
- Turn text and replies use Laravel encrypted casts with the existing APP_KEY. `sales-assistant:maintain` removes text after 30 days, even for conversations that remain active. It retains pseudonymous usage events and completed-checkout attribution, not copies of media. OpenAI abuse-monitoring retention is not eliminated by `store:false`; the privacy disclosure says so.

## Usage and conversion report

Administration → Reports → **مساعد المبيعات — الاستخدام**, route `/admin/sales-assistant`.

The report counts distinct assistant browser visitors (not verified people), conversations, accepted customer messages, replies by outcome, recommendations, selections, cart additions/edits, reviews, completed checkouts, WhatsApp openings and upsell declines. It records token input/output, cached input and reasoning breakdowns, tool counts, processing time, conservative USD cost and unresolved reservations. Reasoning tokens are included in output tokens, not charged twice. Daily budgets use Cairo dates. Authorized staff can read paginated redacted transcripts during the 30-day retention period and a recent event timeline. Rejected/rate-limited attempts have counters without storing their text.

Existing `analytics.view` is required. Transcript/configuration access also requires `settings.site.update`. Cost and attributed order value require existing `sales_reports.view`. Assigned-only staff and API agents cannot use the global console. No financial permission was widened. Completed-checkout attribution is unique per checkout and is additional only: native `order_source=website`, original Meta/TikTok attribution and payment ledger remain intact. A WhatsApp opening is not proof of sending; a recorded order is not a recorded payment.

## Limits and asynchronous execution

Default daily cap USD 5, one pending/processing turn per conversation, 5 messages/minute, 40 admitted turns/session/Cairo-day, 4 model tools/turn, 3,000 output tokens, 32KB seed/history input and 12KB per tool result. A USD 0.10 conservative reservation is acquired before dispatch under row locks and settled once from actual token receipts at conservative published rates (including cache-write headroom); this is not an OpenAI invoice. Unknown outcomes retain their reservation. No automatic provider retry or historical job replay occurs. All bounds and tool definitions must be reviewed before increasing them or changing pricing/model capabilities.

The Responses request itself is a single HTTP attempt with a 55-second timeout, run on the dedicated database connection/queue `storefront-assistant`. POST returns 202 without waiting for OpenAI. One worker has timeout 75, retry-after 120, tries 1 and a 192MB recycle threshold; the prepared systemd unit has a 256MB ceiling and graceful 90-second stop. A duplicate queue delivery cannot make a second model call. Maintenance expires stale unstarted turns and marks stale started turns uncertain without retry. A stale queued observation cannot release a reservation once a worker has started. Polling is bounded and stops while the chat/page is hidden. Worker health is reported and a stale heartbeat stops new chat requests while forms remain usable. Disabling the feature prevents new model turns and MCP access; the normal store/cart/checkout remain usable.

No new paid AWS services, instances, queues or static credentials are required. The extra worker is a process on the existing EC2 instance. Do not install/start the prepared unit without release authorization.

## Verification and remaining acceptance

See the final validation record accompanying the commit for counts. Testing uses PHP 8.3, an isolated SQLite database, a dedicated disposable MySQL 8.4 container and no production secrets. Coverage includes multi-child native checkout, packages/products, original advertising attribution, explicit review, duplicate confirmation, stale prices/address/quotes, missing/foreign photos, cart editing, session ownership, CSRF/rate/quota limits, five-tool MCP isolation, output grounding, provider failure, retention, report permissions and real committed MySQL reservation races.

The deterministic Egyptian-Arabic retrieval fixture covers prayer, football, parents, coloring and screen-free activities with a top-three recall gate of at least 90%. This tests retrieval, not GPT-6 Luna's real recommendation quality. Model replies in local tests/browser QA are synthetic. The live account model-list lookup returned HTTP 200 and exact `gpt-6-luna`, with no generation request and no stored credential receipt or production setting update. Real Responses/MCP behavior, the 90% model-ranking acceptance target and real conversion uplift remain unverified. No synthetic paid AI/customer-facing production test is permitted by AGENTS.md.

## Authorized release procedure (prepared only)

1. Re-read `docs/aws-deployment-runbook.md`, inspect live SHA/clean state, rebase/review any drift. Select exact application SHA and explicit deployment approval; preserve primary local changes and the current `orders_visibility=assigned` setting.
2. Checkpoint current SHA, changed files, manifest/old asset hashes, native config/permissions and a verified database backup using the runbook. Preserve APP_KEY, encrypted credentials and production `.env`/cached config. Never print these.
3. Apply only `2026_11_06_200000_create_sales_assistant.php`. Do not execute test migrations, all pending migrations or a destructive rollback. Code must not be advertised before its tables exist.
4. Ship committed source/build against the same lockfiles, retaining all prior hashed assets. Rebuild the affected route/view/config caches using existing ownership and narrow runbook steps. The additional connection needs a scoped config refresh; no shared-cache flush.
5. Leave `sales_assistant_enabled` absent/0. Verify native authenticated/public HTTPS behavior, exact SHA/file hashes, all old assets and new error logs. Do not restart unrelated workers or touch in-flight Codex/Auto Pilot work.
6. Install `ops/herokid-storefront-assistant.service` only after approval; exactly one instance. Enable it with an explicit reviewed deployment, not as an incidental check. Scheduler already runs the assistant maintenance command every five minutes after the migration.
7. Before enabling chat: a fresh shared-cache worker heartbeat is required (90-second window); the owner reviews/approves the experience; verify the exact model with the protected native read-only check using the current encrypted server key; review the daily cap, queue health and acceptance limitations. Do not silently substitute a model. Retest model availability after key changes/expiry. Observe genuine authorized customer traffic; do not create synthetic production orders, paid AI tests or outgoing messages.
8. Verify the actual deployed SHA, changed-file hashes, authenticated report permissions, public checkout behavior, one worker, scheduler and error logs. Update the runbook's live dated section only after deployment actually succeeds.

## Containment and recovery

Use the protected native switch to disable the assistant first. Gracefully stop only its worker (wait for in-flight request or classify its turn uncertain). Preserve reservations when provider outcome is unknown. Keep all completed orders, ledger, photos, receipts and attribution. Native browsing/checkout continues. Prefer forward repair; code-only rollback requires drift review and compatible old assets/config. Do not migrate down, restore a database, clear queues/Redis, retry failed historical jobs or remove customer media. The release-specific production recovery checkpoint does not exist until an authorized deployment creates and verifies it.


Official reference consulted: [GPT-6 Luna model](https://developers.openai.com/api/docs/models/gpt-6-luna), [remote MCP tools](https://developers.openai.com/api/docs/guides/tools-connectors-mcp). Model-list availability is not proof that a real queued response and all remote-tool behaviors have passed acceptance.

## Validation record — 7 November 2026

- PHP 8.3 full affected regression set: **152 tests / 1,580 assertions on EACH of isolated SQLite and MySQL 8.4**.
- Final assistant/checkout/admin focus after worker-health and memo guards: **28 tests / 269 assertions on EACH database**. Additional committed MySQL race: **1 test / 42 assertions**, 12 concurrent independent reservations and 6 deliveries of the same nonce; cap and uniqueness held. Separate activation/health permission focus: 4 tests / 35 assertions.
- JavaScript state/security tests: **5 passed**; PHP/JavaScript syntax, `git diff --check`, Pint **43 PHP files**, route-cache generation/list/clear and all Blade compilation passed.
- Lockfile build passed: **19 manifest entries**, all **67 prior hashed assets preserved byte-for-byte**. No absolute dependency paths or lockfile changes.
- Isolated Chromium browser QA: desktop drawer/full page, real 390px mobile viewport, same conversation/cart across navigation, native personalization form, keyboard Escape/focus, separate WhatsApp position and authenticated usage view; **zero JavaScript errors**. Local queue execution uses synthetic Responses receipts and the real database queue worker, not an OpenAI generation. The HTTP-only harness removes only its own upgrade-insecure-requests policy and blocks external browser requests; deployed HTTPS/CSP was not altered. Screenshots are local review artifacts, not evidence of a live release.
- Live read-only prerequisite: existing server credential could list exact `gpt-6-luna` with HTTP200; no generation, order, payment, shipping or outbound customer message was requested. Real model-ranking quality, remote OpenAI-to-MCP interoperability and physical-device/production-load performance remain acceptance limits.
