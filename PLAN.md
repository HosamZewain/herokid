# HeroKid Hybrid Agentic Automation — Phase 1

Status: approved architecture; implementation in progress, **Shadow Mode only**.
Owner amendments: 2 November 2026. No AWS deployment, production migration,
customer messaging, production generation, or autonomous execution is authorized.

## Baseline and scope

- Dedicated branch: `codex/agentic-shadow`, isolated checkout. Base verified on
  AWS: `683389a1f2071fa997b6fdbb9f6f37fc96f4028b` on 2 November 2026.
- Preserve the original dirty mobile/UI checkout and every current workflow,
  payment, approval, assignee, manual hold, production attempt and queue.
- Cover all active checkout groups, including existing, held and uncertain work.
  Observing an old order never authorizes enrolling it or replaying its jobs.
- One central operational dashboard, without chat. Show production, existing
  outputs, human work, operational counts and decision evaluation separately.
- Reuse existing lifecycle, unit keys, immutable review manifests, purchased
  component snapshots, product stages and positive-recorded-payment rules.
  Do not introduce a payment percentage or change existing product behavior.
- Runtime reads must not call mutating readiness, preview, polling or admission
  methods. Factor/use pure predicates and verify parity with the existing rules.

## Milestone 0 — plan and regression baseline

1. Record current release, instructions and clean feature-branch identity.
2. Run the existing confirmation/process/identity/payment-review/ownership/
   puzzle/Codex/product-review suites before implementation, using synthetic
   data, an isolated SQLite database and an isolated MySQL instance.
3. Run the fake-CLI worker tests. Record actual commands, versions and failures.
   No production credentials, customer media or external service access in tests.
4. Repeat the same regression selection after implementation; do not disguise
   pre-existing failures, skipped tests or environment limitations as passes.

## Milestone 1 — Order Context and Capability Registry

- Add a read-only `OrderContextBuilder` with an explicit, versioned, allowlisted
  snapshot of checkout/order/unit identity, states, approvals, payment evidence,
  ownership, holds, attempts, existing outputs, missing scenes/pages and human work.
- Keep every child/story/item/component isolated by existing stable identifiers;
  preserve explicit linked-story relationships and retired component history.
- Never sum duplicated checkout balances across sibling orders. Surface conflicts
  instead of silently correcting data. Include cancelled/unsupported siblings as
  facts without treating them as active production targets.
- Model context uses opaque references and facts, not names, phones, addresses,
  media bytes, access-bearing URLs, credentials or raw message/prompt transcripts.
- Fingerprints cover decision-relevant facts and policy/spec revisions, excluding
  read timestamps and transient progress that cannot change the next decision.
- Add versioned production specifications and a compatibility resolver. Explicit
  variant specification precedes product specification, then existing configuration.
  No automatic catalogue conversion or historical snapshot migration.
- Capability Registry describes existing confirmation, identity, single-image,
  story-scene, book-cover/interior, preview, attachment and human-review capabilities.
  Unknown capabilities remain human work; configuration cannot invent an executor.
- Operational context and human-task projections remain available without any AI.

## Milestone 2 — Decision Provider, policy, bounded multi-cycle simulation

### Decision contract and safety

`DecisionProvider::decide(input): DecisionResult` supports deterministic test
fixtures and a Codex SDK adapter. Strict schema: decision, action, target_type,
target_id, reason_code, reason, confidence, context_version, requires_human.
One action maximum per cycle. Unknown fields/actions/targets and stale revisions
are rejected. Confidence never overrides deterministic policy.

`PolicyEngine` reads fresh state and returns allowed/blocked plus reason codes.
`ExecutionGateway` implements **simulation only**. It cannot reserve business
operations, dispatch existing production jobs, approve, message, attach, charge,
print or ship. Do not register write tools or an execution activation switch.
Document future operation IDs, action/input idempotency, authorization, optimistic
concurrency, compatible lock ordering, atomic reservations and reconciliation.

### Bounded sequential evaluation

- Coverage is tracked per `(checkout, context fingerprint, target)`; selecting
  one unit does not mark its siblings evaluated.
- Each cycle receives the full checkout and the remaining uncovered targets.
  Decisions already proposed are recorded as observations, never applied as
  hypothetical successful work or used to satisfy another unit's prerequisites.
- Default maximum **3 sequential cycles per checkout pass**, configurable 1–10.
  A pass yields to other checkouts and preserves outstanding coverage for the
  next pass. A snapshot needs at most one accepted decision per distinct target.
- Stop on exhausted targets, daily budget, changed context, timeout, invalid
  output, or a verified checkout-wide blocker. A model's generic `wait` alone
  cannot stop independent eligible units: it is rejected as false waiting.
- A verified global blocker can cover affected targets deterministically without
  making duplicate AI calls; expose this as policy coverage, not model evaluation.
- Invalid/failed cycles are visible and do not auto-loop on the same input.
  Uncovered targets remain counted; recovery requires changed facts or an explicit
  operator re-evaluation, bounded by the same quotas and deduplication rules.
- Oversized contexts fail visibly and retain uncovered-unit counts; never silently
  drop children/units to fit a prompt.

### Scheduling and persistence

- Configurable default **100 provider attempts/day**, Cairo calendar date, atomic
  quota reservation, one provider call at a time, 90-second timeout. Retries count.
- Prioritize materially changed blocked/uncertain orders, then other changed
  orders; age/round-robin fairness reserves progress for never-evaluated orders and
  unfinished coverage. Unchanged blocked orders do not repeatedly spend AI calls.
- Coalesce changes for 30 seconds. Reconciliation every 5 minutes scans 50
  checkouts using a persisted rotating cursor; events are hints, snapshots are truth.
- Track last observation, context version, last evaluation, pending targets,
  quota-deferred, failed, stale and never-evaluated states explicitly.
- Updating operational snapshots/counts does not require a provider, authentication,
  quota or an AI job. AI failures must not break existing order transactions.
- Additive tables: `ai_checkout_states`, `ai_agent_runs`, `ai_order_decisions`,
  `product_production_specs`; small scheduler/quota state only if atomic cross-order
  invariants need it. Do not duplicate business ledgers/assets/messages/tasks.
- Store sanitized audit data and provider/model/CLI/SDK/prompt/policy/spec versions.
  No raw provider transcripts. Never clear existing leases or production queues.

### Codex isolation and subscription authentication

- Use official Codex SDK behind a local, dedicated bridge with an explicit schema
  and bounded I/O. The initial decision model has **no tools**, including shell,
  filesystem, image generation, web, MCP, apps, hooks or sub-agents.
- Isolate the OS account/filesystem/network from Laravel, database, S3, instance
  metadata, production worker spools and application secrets. Only sanitized
  request data and SDK-owned runtime/auth state are available to trusted bridge
  code. Model capability flags are defense in depth, not the sole boundary.
- Verify the actual installed SDK/CLI behavior and tool inventory. Test attempts
  to run commands, write files, read sentinel secrets and escape isolation. Record
  evidence tied to the precise runtime/configuration; an env boolean is not proof.
- Require supported ChatGPT subscription authentication. Never copy Desktop
  credentials or inherit API-key/provider configuration. No silent paid API fallback.
- Fail closed if authentication, tool disabling, sandbox or verification evidence
  is absent/expired/mismatched. Mock operation must be labelled as mock; failure
  cannot silently select it and pretend a real decision was made.
- Real anonymized/synthetic evaluation may run only after these gates pass. Live
  AWS service setup/authentication remains a separate deployment operation.

## Milestone 3 — Dashboard and real decision evaluation

### Operational dashboard

Protected `/admin/agentic-automation`: current operational facts, evaluated and
unevaluated groups, per-unit coverage, pending/blocked work, proposals/reasons,
policy outcomes, simulation, history, usage and errors. Filter by product, state
and decision. Link to existing authorized order/output pages. Preserve financial
permissions. Clearly distinguish proposed, simulated and existing actual activity.
No execution controls, production enrolment or staff reassignment.

### Representative evaluation corpus

- Build an allowlisted, anonymized corpus from bounded read-only observations of
  actual HeroKid scenarios. Do not export customer messages, media, names, phones,
  addresses, original identifiers, secret configuration or payment instruments.
- Preserve meaningful topology and business facts: mixed children/products,
  exact unit independence, statuses, approval/hold/ownership signals, payment
  eligibility, output counts, missing pages and uncertain outcomes.
- Stratify across actual confirmation, identity, story, puzzle, mug, book,
  payment-review and manual-handoff scenarios. Label synthetic counterfactuals
  separately when rare safety scenarios are absent from the observed sample.
- Expected outcomes are reviewed business labels (sets of acceptable next
  decisions/targets and forbidden actions), not generated by the policy under test.
- Version the dataset, provenance method, expected labels and content hash.
  Produce a concrete reviewable set for owner approval; do not claim it approved
  merely because the architecture was approved.
- Report real-provider correctness, unsafe proposals **before** policy filtering,
  unsafe proposals admitted by policy, false waiting, unsupported/unknown cases,
  coverage, schema/provider failures and differences from existing automation.
  Report mock and real results separately; missing/failed cases remain visible.
- Target **at least 95% correctness on the approved evaluation set**, with no
  unresolved safety-critical failures before any later live execution. This phase
  cannot activate execution even if the threshold is met.

### Test coverage and milestone reporting

Cover all original 18 scenarios plus multi-cycle sibling coverage, bounded loops,
deduplication, priority/fairness, quota boundaries/concurrency, stale contexts,
provider outages with fresh operational views, dataset privacy and false waiting.
Cover book sizes/links, retired components, duplicated balances, local-only reads,
auth/financial scoping and prompt injection. Compare business tables before/after
shadow activity and prohibit HTTP, queue and media side effects.

After **each milestone**, report changed files, exact test outcomes, architecture
decisions and remaining risks in the conversation and implementation report.

## Milestone 4 — read-only MCP after core validation

Keep MCP thin and optional. Reuse validated query services for `get_order_context`,
`get_product_spec`, `get_available_actions`, `get_production_status`,
`get_customer_interactions`, `get_payment_status`, `get_human_tasks`.
Use explicit schemas, dedicated read ability and existing order/financial policies.
No generic SQL/HTTP/filesystem/shell or write tools. Do not force internal Laravel
calls through MCP. Proceed only after the core tests pass; avoid framework or
transport complexity that is not necessary for these seven reads.

## Delivery and deployment boundary

- All new automatic scheduling/provider settings default off; Shadow is immutable.
- Build committed frontend assets from this branch's lockfile in isolation and
  preserve earlier hashed assets when the dashboard requires an asset build.
- Document installation, isolated validation, limits, evidence, observation-only
  reconciliation and stopping the new components without changing legacy work.
- No AWS deployment, production migration, service/config change or new recurring
  AWS resource. Future deployment requires explicit approval and runbook checks.
- Later execution phases require separate review of ownership transfer, one
  capability/cohort at a time, idempotency and the approved real evaluation gate.
