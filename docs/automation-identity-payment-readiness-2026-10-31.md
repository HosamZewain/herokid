# Identity review and payment readiness — 31 October 2026

Status: **prepared, not deployed or activated**. This continues the owner-requested automation work after message-history release `07cc0535673d59f8492d91adb1390f09573c6664`, with documentation base `524627e`. Current isolated branch: `codex/order-process-trial-readiness`.

## Revalidated production

Read-only SSH confirmed live SHA `07cc0535673d59f8492d91adb1390f09573c6664`. Existing documented cache-file mode change and three retained CSS files are unchanged. Database identity `herokid`; bounded observation: 2,694 orders, 3,385 payment events, 81 failed jobs, default queue ready/reserved zero, no process runs or process message attempts. Legacy future-order confirmation remains enabled. Generic process, identity and identity-template gates remain off. Owner user1 still lacks `order_automation.execute`; no permission grant was applied.

## Fixed expected payment changes during identity review

The process fingerprint previously froze both payment columns and the duplicated payment fields inside `delivery_details`. A normal deposit recorded through `OrderPaymentService::updateGroup` therefore invalidated the customer's signed preview (HTTP404) and blocked identity confirmation. A synthetic regression reproduced that failure before the fix.

New contexts use fingerprint version2, excluding only mutable payment balance/status and the four payment mirrors (`payment_status`, `payment_method`, `paid_amount`, `remaining_amount`). Order identity, customer contact, source photos, price lines, quantities, discounts, shipping fee and business status remain guarded. Unversioned contexts continue using the previous strict fingerprint; no stored contexts are rewritten. Unknown fingerprint versions fail closed.

The preview template already calculates current totals and remaining balance at send time; the new regression verifies a150 EGP synthetic checkout with75 EGP recorded payment sends150.00 total and75.00 remaining. A payment during review leaves the private preview usable and permits the exact correlated identity approval. It does not add/change payment events, alter the paid balance, mark the order fully paid, or release production. The final payment/manual review gate remains.

## Read-only trial candidate preflight

New command:

```text
php artisan order-process:identity-preflight <order-id> --automation=<workflow-id> --actor=<user-id>
```

This inspects an exact saved revision and actor permissions, reporting both its stored checksum and a canonical fingerprint of its current definition; requires a single confirmed story order, one identity-generation step and one identity-preview message; detects existing process/identity ownership and existing canonical outputs; validates selected story, child inputs and up to three actual photo files using the same input adapter; checks configured provider/model availability and model-pricing estimate. It emits only IDs, checksums, counts, booleans, error codes and model/budget facts. No customer names, phone numbers, prompts, photo paths, signed URLs or credentials appear.

Exit0 means **candidate validation only**, never authorization or operational readiness. Output always has `execution_authorized=false` and `external_delivery_and_workers_verified=false`. Enabled flags are observed, never changed. No events/runs/attempts are created, no jobs dispatched, no provider calls, no uploads. The command does not enroll historical orders or fabricate a status transition. A failed check returns1, invalid IDs return2; exception text is not exposed.

A configured estimate is not a vendor quote or a hard spending cap. Runtime budget checks, activation policy, current permissions, exact trial scope, provider/template verification and worker checks still apply at execution time.

## Concrete trial still to approve

The owner has been asked for a story order reserved for a trial on the previously approved test recipient; no order has been selected in this continuation. Do not reuse the earlier confirmation-only synthetic order without suitable authorized child photos and a story. Do not choose a real customer order, create a synthetic production order, grant permissions or start a billable generation based on generic continuation alone.

After selection, run the preflight and prepare one reviewable packet containing:

- Exact order ID, single-order checkout, approved recipient check (kept private), workflow/revision/canonical definition fingerprint and input fingerprint.
- One identity image using existing OpenAI settings and an explicit estimate ceiling; no automatic uncertain-result retry.
- One `herokid_identity_review_payment` message, `ar-EG`, eight ordered variables, current numeric total/remaining; existing open conversation may be closed under the agreed transport behavior.
- Exact required actor grants. Runtime activation requires `order_automation.activate` **and** `order_automation.execute`, plus the existing order/photo permissions. The pending earlier execute-only grant for manual message retry does not cover activation.
- Scoped process/AI workers on the existing EC2; provider180s < job240s < lease270s < Redis retry_after300s for AI. Verify transport timeout ordering and available RAM independently.
- Trial config pinned to this order/workflow, appropriate boundary and legacy confirmation ownership retained. A previously confirmed order needs separately reviewed explicit trial enrollment; never flip its status backwards to manufacture an event or replay historical events.
- Customer approval/revision response and private preview verification, then stop at manual payment review. No payment, shipping, printing or story-scene generation.

No new recurring resources were provisioned. No paid AI, template send, trial order, production config/data change or permission grant occurred in this continuation.

## Validation and release boundary

Final verification: 137 tests /736 assertions on isolated SQLite; isolated MySQL passed136 tests /732 assertions plus the added canonical-fingerprint regression (1 test /4 assertions). Fake HTTP/AI throughout. PHP8.3 syntax passed for all changed PHP files. Tests explicitly prove no DB writes, queued jobs or external HTTP during successful preflight, and that revoked permission prevents reading input photos. Application test runtime is PHP8.5; deployment compatibility lint is PHP8.3.

MySQL initially exposed JSON object-key reordering in the new preflight checksum comparison. The check now computes a canonical content fingerprint, independently of the historical insertion-order checksum. Regression proves reordered keys retain the same fingerprint while changing the budget changes it. The stored revision checksum is reported as historical metadata, not falsely claimed to verify current content.

No frontend assets, schema or production environment changes are required. If approved for deployment, compare live SHA and drift again; checkpoint code and caches, deploy only this release, refresh loaded PHP and appropriately scoped workers if any process workers have since been installed. Leave all activation flags and permissions unchanged. Old contexts remain strict; v2 runs require preserving the corresponding code if any are later activated. Do not restore an old database as rollback.
