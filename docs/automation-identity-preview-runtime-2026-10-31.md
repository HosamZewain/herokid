# Identity generation, preview and delivery receipts — prepared, not deployed

31 October 2026. Isolated branch `codex/automation-process-builder`, continuing `5cccb56068aa4a84d2a8a6362b9fb08287c5e842`. Production was re-read during this work as `adfaffa9841c79a16dd3d37383e2f3209a6736f1`. No production code, configuration, orders, workers or files were changed. No real AI call, customer message, payment or shipping action was performed.

## Implemented path

The new **identity** preset starts on a future transition into `new_confirmed` for a checkout containing stories. It generates and registers one identity per story order, sends the preview/payment template, waits for a correlated response, then either approves those exact identities or routes the request for staff review. Payment review remains a staff step. An identity confirmation never creates a payment, changes the paid balance, assumes a deposit, or releases production.

- The designer supports `generate_identity` with a per-checkout USD estimate ceiling and `approve_identity` linked to a response step. Both are available in synthetic simulation. Branches, immutable revisions and existing statistics remain shared with other workflow types.
- Generation reuses the existing child-identity provider/model settings, OpenAI client, image pricing and canonical `child_identity_requests` / `child_identity_generation_attempts`. It uses the existing order identity prompt and up to three verified order photos; snapshots retain order/child/story/photo checksums. Output goes to `media.private_disk` (production `s3_private`), with canonical checksum, usage and cost metadata.
- A unique automatic claim per order prevents two workflows or revisions charging for the same initial identity. Legacy live claims and existing canonical attempts/outputs block for review. The manual canonical generator also refuses unresolved process ownership. Nothing retries an uncertain provider result automatically.
- Provider/model/pricing, order context, original photo bytes and actor permissions are revalidated. Changed input, revoked permissions, missing output, a missing worker or expired lease stops for review. A late result is retained without automatically resuming the run. Actual provider cost may exceed an estimate; the configured budget is a preflight estimate ceiling, not a provider spending cap.
- Each checkout must include all its story orders. Partial story selection cannot send a misleading preview that omits a sibling. The private preview explicitly states that confirmation approves **all identities shown**. An approval uses the existing canonical approval service, with source `robodesk_process`; payment remains unchanged.
- Signed customer preview page: 35 days; individual image links: at most 15 minutes and never beyond page expiry. Exact run/task association, image checksum, private/no-store/no-referrer/noindex headers and no external trackers. Changed order/contact context or blocked/cancelled/uncertain runs invalidate access. Possession of the signed link grants access during its validity; do not put it in reports or logs.

## Meta / RoboDesk template contract

Template **`herokid_identity_review_payment`**, ID **`1394505159141810`**, Arabic `ar`, Utility, submitted to Meta. Last console verification in this work: **pending review**. Submission is not approval and not RoboDesk catalogue synchronization. No trial send has been performed for this template.

Send language is `ar-EG`, with no `procedureId`, and these eight positional values:

| Position | Field | Meaning |
| --- | --- | --- |
| 1 | `customer_name` | Parent name |
| 2 | `order_reference` | Checkout display reference |
| 3 | `story_names` | Story titles |
| 4 | `child_names` | Exact children |
| 5 | `product_names` | Products, or explicit no-additional-products text |
| 6 | `identity_preview_url` | This run's signed private identity preview |
| 7 | `order_total` | Numeric amount including shipping and after discounts |
| 8 | `remaining_amount` | Numeric actual unpaid balance after recorded payments |

Quick replies: `تأكيد الهوية` and `طلب تعديل الهوية`. Wallet/deposit wording follows the owner's submitted template. This is a preview/payment-request message, **not payment evidence**. The existing settings template's product gallery URL is not substituted for the new identity preview.

Existing close/send safety is retained: close with completed status, bounded close re-read recovery, only exact documented no-send 501 retry once, no automatic retry after uncertain sending. Responses are matched by incoming text and exact stored template wamid, or the latest evidenced template in the same interaction for typed text. Millisecond ordering ambiguity and mismatched interaction evidence stay unconsumed. Poll newest 50 interactions without `after`, deduplicate by message identity.

## New delivery contract

RoboDesk's owner supplied the live `messagesByPhone.delivery` contract during implementation. This code consumes it; the reported real-order outcomes were **not independently re-polled in this change**.

- `delivered`: reached the phone; `sent`: accepted but arrival not proven; `failed`: staff follow-up through another channel; absent/null/unrecognized: **unknown**.
- Delivery is accepted only from an outbound named template matching the stored wamid and interaction (and the frozen template name for process runs). It is read during existing full-window polling, without new recurring provider polling resources.
- Status/category are recorded independently of send acceptance and customer confirmation, and shown in legacy confirmation history and process steps. Provider `reason` text is deliberately not persisted/exposed: it may include customer or provider details.
- Failed legacy delivery sets a review marker; no resend, SMS, call or order-status mutation follows. Existing polling stays active. A later valid customer confirmation still uses the usual correlation guards and is not discarded solely because of an old failed receipt. Null does not mean success. Successful sends with no current delivery evidence remain unknown.
- Polling is optimized for pending responses, not an indefinite delivery/read analytics feed for completed runs. Older completed records can lack delivery observations. The 50-interaction window is not guaranteed historical completeness; missing evidence never authorizes a resend.

## Deployment and activation gates

This increment is prepared only. **Separate deployment approval is required.** Builder/runtime code can be deployed with execution off, preserving the live legacy confirmation owner. Apply the earlier `160000` / `170000` process migrations and the new `180000` migration in reviewed order only after backup/target verification. The new migration creates identity task records and makes canonical consent fields nullable: existing-order automation must not invent a new customer consent event. It does not backfill, generate or modify customer orders. Its down migration intentionally does not restore non-null consent constraints.

Defaults remain:

```dotenv
ORDER_PROCESS_ENABLED=false
ORDER_PROCESS_CONFIRMATION_OWNER=legacy
ORDER_PROCESS_IDENTITY_ENABLED=false
ORDER_PROCESS_IDENTITY_TEMPLATE_READY=false
ORDER_PROCESS_IDENTITY_BUDGET_USD=0
```

Before separately approved activation: verify actual Meta approval and RoboDesk template availability; freeze a future-order/event boundary and reviewed workflow revision; confirm photo-processing authority, model/prompt/price, per-checkout budget and current EC2 memory capacity. Install the reviewed worker only within that approval, using existing EC2/Redis. Example `ops/supervisor/herokid-order-process-ai.conf.example` is **autostart=false**, one worker. Job timeout 240s < task lease 270s < Redis retry_after 300s; provider call timeout 180s and maxAttempts 1. Existing process transport worker remains separately scoped. No worker has been installed.

First real generation/send requires a bounded approved trial. Existing confirmation can remain owned by legacy while identity handles a later confirmed-status event. Deploying this branch does not authorize activation or historical event replay.

## Recovery and remaining scope

Pause future execution before recovery; preserve receipts, canonical outputs, costs and claims. Do not roll back these tables after real executions or simply remove ownership guards. Uncertain paid identities cannot be released with the ordinary stop button. Inspect provider/canonical evidence and reconcile explicitly; no force-retry/release UI is supplied. A successful reviewed output may be retained even if its surrounding workflow is blocked.

Still not implemented: automatic payment-proof ingestion/reconciliation, per-story partial approval in one checkout, regeneration loops, reusing a previously approved identity in this preset, production/shipping adapters, arbitrary live template contracts and general child-item joins. The order stays in its current business status while the process tracks generation/waiting separately; no new order statuses were invented. Existing identities with previous output stop for review. This is progress toward the requested automation target, not a claim that 60–70% is already automated.

No new recurring AWS resources or services: **$0 additional fixed AWS resource cost**. Activated AI/WhatsApp usage and existing S3/EC2 consumption remain metered, so runtime activation is not cost-free.

## Validation

Synthetic tests only, fake external HTTP/AI, no production credentials. Evidence directory: `/tmp/herokid-process-identity-evidence/`. **261 tests / 1,737 assertions passed on each of SQLite and isolated MySQL 8.4**, covering identity, process builder/runtime, legacy confirmation/workflow, canonical child identity and conversation history/replies. After excluding visit tracking from private preview routes, the 26 identity tests / 195 assertions passed again on both databases. Five frontend browser/module tests passed. PHP 8.3 syntax validation passed; full suites run in the available PHP 8.5 container.

Production asset build and manifest target verification passed; previous hashed assets retained. Synthetic editor/live/customer pages rendered successfully, with no JavaScript errors or page overflow at 1440px; customer preview also verified at 390px. Customer gallery screenshot uses clearly synthetic image placeholders, not an AI generation or customer photo. A missing local logo in the synthetic admin fixture is not a changed production asset.
