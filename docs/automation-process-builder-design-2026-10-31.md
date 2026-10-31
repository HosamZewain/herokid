# HeroKid configurable process automation — proposed design

Date: 31 October 2026. Status: architectural proposal, not implemented or deployed.

## Decision

Build a durable, configurable workflow layer inside HeroKid using the existing Laravel, MySQL, Redis, scheduler and workers. Reuse domain services for confirmation, identity, payment review and Production Studio. Do not move order/payment ownership to a separate automation product or add recurring infrastructure for this phase.

This is a workflow-engine change with a new UI, not a cosmetic redesign. The existing foundation is useful but is not a general process builder. No production state, workflow activation, messaging or AI execution was changed during this assessment.

## Evidence and current gaps

Inspected isolated checkout `/tmp/herokid-order-confirmation`, commit `8542cbac23c30ed8cd74436bceb4e5b5a33259cc`; production history was read from the deployment runbook, not re-verified by SSH during this assessment. The primary checkout has unrelated work and was not used as a release base.

| Component | Existing capability | Limitation relevant to this request |
|---|---|---|
| `WorkflowDefinition` | Named workflow definitions, model/prompt/budget settings, selected stories | Schema 1 permits only `new_order`, only story targets, and fixed ordered prepare/generate/save/review/whatsapp/wait steps |
| `WorkflowService` | Draft publication, immutable revision records, separate activation, request deduplication, cross-workflow identity lock | Scans increasing order IDs; an already scanned order changing status does not trigger a new run |
| `WorkflowRunner` | Leases, input fingerprints, budget reservation, asynchronous provider polling, uncertain-result blocking | Hard-coded switch; WhatsApp throws `delivery_not_configured`; wait ends at preview-ready rather than implementing arbitrary durable customer-response branches |
| Identity save step | Private generated image checked and attached to the exact order | Saving an attachment is not the same as registering/approving a version in the canonical child-identity lifecycle |
| `OrderConfirmationService` and RoboDesk polling | Checkout-level confirmation, original-message correlation, future-only eligibility, deduplication | A separate specialized mechanism, not an editable workflow node set |
| `PaymentProofService` / proof review | Private evidence storage and review foundation | Receipt ingestion exists in code but a working RoboDesk polling contract for attachments must be verified; current `approveProof` marks the checkout paid in full and ready for print, so it cannot be reused unchanged for arbitrary deposits or identity-stage workflows |
| `ProductionAutomationRunService` / state machine | Preflight, budget limits, one active project run, protected state transitions and production snapshots | Specialized production executor; reuse through an adapter rather than duplicate or bypass its safeguards |

The legacy RoboDesk inbound event handler contains identity/rejection branches, but their presence does not prove a live provider integration. RoboDesk's agreed contract is generic polling, not HeroKid-specific callbacks.

## User interface

Landing page: workflow list with name, draft/active/paused state, trigger summary, enrolled count, waiting/customer-review count, completed/blocked count and external spend where available. Open any workflow to edit its draft, inspect versions, view runs or pause new enrollment.

Builder:

1. Name and scope: checkout, story item/production unit, or product item/production unit.
2. Start event: checkout finalized, order status entered, identity decision received, verified payment recorded, production stage completed, or explicit authorized enrollment.
3. Conditions: order type; all/selected stories; all/selected products and variants/SKUs; current status; other supported business fields. Explicit AND/OR groups and readable summaries.
4. Steps: change status, generate identity, save/register output, send approved template, wait for a correlated response, branch, wait for several required conditions, request staff review, start an existing production process, finish.
5. Each node has validated inputs, named outputs, permissions, timeout and failure behavior. Map template variables from approved fields, not arbitrary code or SQL.
6. Show a readable visual flow; begin with a structured step builder plus branches rather than making drag-and-drop wiring a prerequisite.
7. Validate and simulate using synthetic fixtures; preview eligibility separately without executing. Publish a version, then explicitly activate it for future events.

Running instances retain their published version. Editing a draft does not change active runs. Migrating a waiting run to another revision is a separate explicit operation with a compatibility check.

## Runtime design

- MySQL is the durable source of workflow progress; Redis jobs only advance ready steps. Waiting for a customer must release the worker, not keep a job sleeping.
- Store the business change and its durable event in the same DB transaction. A dispatcher delivers committed events to the workflow engine with deduplication. Audit all status-write paths, including bulk query updates that bypass model hooks.
- Distinguish an event from a condition: `status entered new_confirmed` starts a flow; `contains selected stories` filters it. Do not repeatedly start a flow just because a row still matches a condition.
- Proposed records: definitions/revisions, workflow instances, per-node executions, durable business events/outbound intentions, response subscriptions. Reuse existing structures where semantics fit; do not duplicate entire orders, photos, payment ledgers or conversation histories.
- Unique execution identities include workflow enrollment, scope, item/version and node invocation. Merely changing workflow revision must not authorize repeating a paid side effect.
- Use a shared action registry with typed inputs/outputs and provider adapters. Step execution claims and receipts survive worker restarts; leases prevent simultaneous local execution.
- External exactly-once execution cannot be guaranteed without provider support. An ambiguous send or generation result pauses for reconciliation; local retries must not create another charge/message.
- Publish-time validation rejects cycles without bounded repetition, missing outputs, unsupported templates/actions and incompatible scopes. Runtime causation, re-entry rules, expected-state checks and limits protect against cross-workflow loops and conflicting status changes.
- Recheck cancellation, item identity, reference-photo/prompt version and relevant state before each side effect and before accepting its result. A late result must not overwrite a newer identity or cancelled order.
- Keep business status, workflow execution state and financial state separate. A workflow waiting for a response is not itself a new order status.
- Pause-new-enrollment and pause-before-next-side-effect are separate controls. Keep safe receipt reconciliation available after a pause; do not automatically replay old failures on resume.
- Preserve manual controls. Staff can inspect why a run is blocked, correct missing input, approve a review or stop a run, with permissions and a recorded reason.

## Scope and mixed orders

Confirmation is one message per finalized checkout, not one per child/story row. Identity generation is per exact story/child/production unit. A checkout containing two stories and a product must generate only the intended story identities and must not send three order confirmations.

Each child operation retains its own identity/version and approval. A join waits for the explicitly required child results before a combined next step. Show partial success; do not treat one child's approval as approval of all children. Product quantity does not by itself define the number of distinct personalization units.

## Example A: confirmation

Finalized new checkout → all products/stories, status `new` → send confirmation template → wait for correlated inbound response.

- Confirm: validated transition to `new_confirmed`, emit one durable event for downstream eligibility.
- Reject: configured business transition after checking current production/payment state; do not automatically cancel an already progressed/paid order merely because an old message is answered.
- No response: configured timeout/reminder or staff task, with frequency and spending limits.

Only the existing confirm template/response is established. Reject buttons, reminders and their template contracts require separate configuration and validation. Preserve the existing future-only boundary and receipts when adopting the live confirmation flow; do not enroll historical orders or send again.

## Example B: identity and payment review

Status enters `new_confirmed` → select matching story units → validate photos/prompt/budget → change to the configured identity-generation status → invoke existing identity generation → verify and privately save output → register an unapproved identity version on the exact story unit → send the configured review/payment template → wait.

Identity decision and payment evidence are independent branches:

- Identity approved: record approval of the specific version. A change request goes to a bounded revision/manual-review path.
- Customer says paid or uploads proof: record a payment claim/evidence for review; do not increase paid totals.
- Authorized finance review or verified payment-provider evidence records the real transaction using the existing ledger workflow.
- Refactor the current proof-approval action before adapting it: require the verified amount, preserve partial payments, and separate recording payment from the decision to release production. Its existing full-payment/ready-for-print coupling is incompatible with a generic process builder.
- Continue only after the configured requirements are satisfied, e.g. current identity approved AND required deposit verified. Support legitimate COD/payment policies explicitly rather than enforcing a universal full-payment gate.

Receiving payment evidence does not implicitly approve identity. Customer approval does not bypass production quality checks. Customer review media delivery, allowed formats and secure preview access must be verified against the actual RoboDesk template contract before activation.

## RoboDesk adapter constraints

- Use generic routes and polling; no assumed events endpoint or provider callback.
- Read pending contacts without `after`, with `limit=50`; deduplicate by provider message identity. Detect missing conversation/template coverage and route ambiguity to review instead of silently declaring no response.
- Only inbound messages can satisfy customer decisions. Match a stored original-template wamid and phone; typed fallback must identify the correct interaction and latest preceding template. Preserve the existing timing/ambiguity protections.
- Poll once per contact and route results to eligible subscriptions, rather than polling once for every workflow on that contact. One inbound decision must not accidentally satisfy several unrelated waits.
- Coordinate outbound work per contact. Owner-approved close-before-send behavior can interrupt another workflow's conversation; schedule prompts deliberately and preserve previous response correlation.
- Keep `ar-EG`, numeric-only amount variables and no `procedureId` for the existing confirmation template.
- Safe close recovery and the exact no-send 501 retry remain distinct from an uncertain send. Do not retry the latter automatically.
- A send acceptance receipt is not proof of delivery or customer approval. Stats must distinguish these states.

## Data retention and cost

Avoid registering ineligible historical orders merely to leave held records. Persist only useful enrollment, execution, receipt/correlation, budget and audit facts. Keep customer content in existing protected stores; store references rather than copies.

The owner's request to remove useless records does not make deduplication receipts or financial audit history disposable. Define retention by purpose: active waits and unresolved outcomes remain; compact completed execution details only after a reviewed retention policy preserves reconciliation and late-response safety. No production cleanup is authorized by this proposal.

Target no new recurring AWS resources. Existing compute may orchestrate bounded jobs, but load must be measured before increasing concurrency. AI calls, WhatsApp usage and storage/requests can still increase variable costs. Set per-action, per-run and daily budgets; measure real spend, not only configured estimates. Keep heavy PDF/image work from exhausting web-request capacity.

## Delivery phases and acceptance

1. **Generic foundation + confirmation as first workflow:** builder, conditions/scopes, revisions, durable instances, safe status changes, template action, response branches, waiting, run inspection. Test duplicate events, concurrent workers, restart during send, delayed messages, multiple checkouts per phone, cancellation and unauthorized edits. Shadow/synthetic validation first; cut over ownership once, without two active senders.
2. **Identity + evidence/review:** adapt canonical identity services and private output registration; implement item versions, budget gates, customer review and payment-proof intake with verified provider contracts. Test mixed carts, changed photos, late outputs, wrong-child responses and payment claims that must not alter balances. Approved limited pilot before broader activation.
3. **Production and fulfillment handoffs:** call existing Production Studio through guarded adapters, integrate verified payment/shipping events, add reminders and exception tasks. Keep physical QA and irreversible shipping actions behind their required controls.
4. **Optimize from measurements:** compare manual touches and staff minutes per eligible order/cohort against a baseline; track no-manual-intervention completion, rework, errors, cost and turnaround. Count only successful, correct outcomes; exclude merely queued/waiting work from automation success.

The 60–70% target is a goal for measured digital operational work, not a guaranteed share of all activities. Physical printing, packing, quality inspection and ambiguous financial/customer cases remain human work unless separately integrated and validated.

No fixed schedule is asserted before inventorying status mutation paths, canonical identity registration, payment policies and template/media contracts. The next implementation slice should be phase 1, not a one-shot replacement of all production systems.
