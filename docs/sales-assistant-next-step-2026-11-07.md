# Sales assistant message-based purchase — 7 November 2026

## Customer behavior

Selection starts a conversation question, not a visible product/order form. Customers send child, recipient and address information as messages, including several details in one message. Native product schemas supply questions for puzzles and other products: variants, quantity, child fields, custom descriptions and package story slots. Native upload controls appear only for photos. Optional answers have a skip button. Earlier answers/photos can be edited before the final native review and explicit confirmation. Existing child details require an explicit reuse choice for a new entry; photos are never implicitly copied.

Cart child/photo flags no longer imply completed delivery or an already submitted order. A read-only probe of two genuine completed turns showed `next=cart`, zero recommendations: the previous browser had ignored this action. Current cart actions open continuation within the conversation, with recovery buttons for older completed turns. An open child/photo entry remains intact until the customer explicitly switches. Current native question context is restored when reopening a frame.

The native form remains an internal adapter so original CSRF, required fields, actual variant IDs, stock/pricing, upload ownership, shipping selectors, quote invalidation and idempotent final confirmation remain authoritative. The model supplies no destination or mutation. Only native photos and final review/receipt are visible. The prior-order shipping decision also uses chat choices; cancellation remains blocked by the server. A null guard fixes the native check after the unsupported cancellation button is removed in the assistant.

## Privacy, accounting and limits

Batch extraction handles labeled details and context-specific compact name/age/gender or recipient/phone/street messages. Ambiguous children are rejected for clarification. Only newly captured fields are applied to the active entry, not an old child's whole draft. Custom schema answers and selection messages use a private, free receipt; they are never forwarded to inference. Sensitive transcript display remains masked in persisted receipts and metrics; current customer-visible answers are transient in the browser. Existing encrypted private draft/session controls remain. Field-presence keys, never names/phone/address/photo paths, enter model context.

Each private answer increments existing message/reply/intake usage counters exactly once; no reserved/spent AI cost or automatic order creation. Native intake has a separate 30-message/minute limit so checkout is not stopped by the five paid-message/minute allowance. All model-bound requests, including questions during intake, retain the five-message limit, daily/session quotas and USD5/day reservation budget. Same exact GPT-6 Luna/low/store:false; no new integration or recurring resource.

## Validation

PHP8.3 isolated SQLite: 92 tests/865 assertions, one MySQL-only concurrency test skipped. Isolated MySQL: 92 tests/907 assertions, none skipped. After the final idempotency adjustment, focused private-intake tests passed on both engines:9 tests/119 assertions each. JavaScript7, PHP formatting, syntax, diff and lockfile/manifest checks pass.

Local Chromium synthetic fixtures exercised message-only story customization and batched contacts through actual two-photo upload, delivery, native review and final order receipt; existing-order separate/shipping choices appeared as chat messages. Personalized puzzle variant/custom description/child data, two photos, photo deletion/replacement/minimum guard, native cart and fresh-child isolation passed. Package child/story choices and two photos reached the native cart.320/390/844/1440 widths and reduced-height composer checks passed; screenshots inspected, zero script errors. Provider recommendation replies were mocked locally; these tests do not establish real-model accuracy or physical-device behavior. No synthetic production inference/order/payment/shipping/customer-message call.

## Publication and recovery

Prepared against verified AWS/GitHub main `0f33c8c311ce4dead40758eef775df1bc2fa9125`; deployment results follow after guarded publication. All existing committed build assets retained byte-identically. No schema/routes/configuration/dependencies/IAM/infrastructure changes. Refresh only the dedicated assistant worker gracefully, rebuild changed Blade views and reload FPM. Do not replay history, clear shared cache or signal unrelated workers.
