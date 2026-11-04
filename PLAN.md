# HeroKid Auto Pilot — controlled execution preparation (4 November 2026)

## Current owner instruction and verified base

The owner now requests actual production, order confirmations and preview messages,
with every decision, step, result, pause and wait visible on the order page. This
supersedes the Phase 1 implementation-only scope. Manual holds, customer/staff
approvals, recorded payments, uncertain charge receipts and existing ownership
remain binding. Autonomous execution is **prepared, OFF and not deployed** in this
working branch. An explicit AWS release/targeted-migration approval is still needed
under AGENTS.md before applying this new release.

Before continuing implementation, GitHub main and AWS HEAD were both verified as
`449cb0194cfaa772ba4ae7c855d7028aaebd9724`; all17 changed-file hashes matched. The
new main guidance, valid same-child photo fallback and explicit untouched-preflight
recovery are merged. The subsequent documentation-only main/AWS HEAD `322a40ff6130d643f18d71a45f34f4006f2b4b64` was reverified and merged; both updated documentation hashes matched AWS. Only documentation merge conflicts required reconciliation.
The primary checkout's unrelated mobile/UI work remains untouched. Branch:
`codex/autopilot-controlled-execution-20261104`.

The latest SELECT-only inventory at11:04:24UTC covered264 active checkouts and610
decision targets without errors. Nine units were eligible for production preflight
(8 product images,1 book cover); this was eligibility at scan time, not permission or proof
of provider admission. All other reasons remain visible; no historical attempts,
paused workflows, completed artwork or uncertain sends are automatically replayed.

## Delivery and admission contract

1. Preserve the isolated ChatGPT Decision Provider, zero tools, source/secret
   isolation and no paid API fallback. Every decision still passes the existing
   Shadow simulation; a **separate trusted application executor** can admit a
   permitted step only after explicit configuration and a fresh policy check.
2. Fail closed unless activation ID/time, allowed actions, checkout scope and a
   separate action/day limit or explicit unlimited execution mode are complete.
   All defaults remain OFF/empty/zero; a missing/zero cap never means unlimited.
   The permanent AI decision limit stays1000/day; it is NOT a production or
   message budget. Existing per-product estimates/caps and Codex pool concurrency
   remain unchanged. No new AWS service, worker resource or recurring spend.
3. Admit at most3 steps per pass on the existing dedicated queue, tries1. A locked
   singleton serializes admission budget/pause state; immutable per-decision
   receipts and operation keys prevent repeats. Native first-attempt checks and
   unique active-unit reservations remain the authoritative final fence.
4. Require a fresh post-activation Codex decision, subscription/zero-tool/isolation
   evidence, current memory and context fingerprints. Lock memory and perform the
   last check **inside each native executor's checkout lock**. Serialize both new
   memory rules and edits through the same admission lock. Use READ COMMITTED for
   the admission transaction only so a committed edit cannot hide in an older
   MySQL snapshot; preserve the worker session default. Keep the established
   Codex-pool lock ordering. Do not call paid providers in the admission gateway.
5. Start product images/covers through PuzzleAutomation first-admission preflight;
   start stories and approved book interiors through their existing services.
   Never use regenerate/resumeQuality/manual-return paths, fabricate payment or
   approval, discard prior outputs, or take ownership from existing automation.
6. Confirmations use only existing unattempted eligible pending outbox events.
   Preview/confirmation workflow messages use only the exact currently queued
   message node, version and approved template of an already enabled owned run.
   A versioned job delegates to ProcessRuntime; it cannot advance a different
   node, enroll history or resend an uncertain/accepted message. Native message
   attempts and receipts retain ownership and billing certainty.
7. Successful admission means queued/accepted, never completed. Read actual
   executor states and record subsequent transitions. Expired decisions cause a
   bounded refresh of only the expired target through the normal AI quota, without
   reopening covered sibling targets or executing old Shadow proposals.
8. Add a persistent stop for **new admissions** accessible to existing global
   automation manage/execute permissions. Existing in-flight work finishes under
   native controls. Observation and order-page read access remain independent of
   model availability; no MCP write tools or new model capabilities are exposed.

## Owner-visible audit and operations

Every new decision (including waiting/manual/escalation), failed decision,
admission/refusal and tracked executor status transition records the Auto Pilot
actor on the existing order activity timeline. Records contain safe reason codes,
unit IDs and native receipt references, not photos, secrets or free-form customer
payloads. Existing immutable decisions remain accessible with memory versions.

The order page shows latest observations, changed-data warnings, current blockers,
AI unavailability/quota waits, recent decisions and native execution states. A
read-only scoped endpoint refreshes every15seconds without AI calls. The global
activity display distinguishes decision work from execution, preserves older
in-flight work in the display, and includes the separate action count/stop control.

## Validation and release gate

- Before:404 selected automation tests passed on PHP8.3 SQLite; after merging the
  latest main,411 passed. Extend validation to confirmation, order timeline,
  all six admission adapters, isolation/authentication, stale/duplicate decisions,
  independent sibling units, scope/pause/quota/expiry, permissions and read-only UI.
- Run final suites on synthetic SQLite and isolated MySQL8.4 with external requests
  disabled; use mocked transport/image results only. Never run tests on AWS data.
- Build from this isolated lockfile, retain earlier hashed public assets, inspect
  Arabic RTL output and validate the manifest/path portability.
- The approved real-model benchmark remains89/89 correct with current approved
  memory; do not relabel mock adapter tests as model accuracy or add unapproved
  cases to its denominator. Additional message admission cases use synthetic
  execution fixtures; no actual customer message is a test.
- Prepare the exact source diff and the one additive migration
  `2026_11_04_100000_create_autopilot_production_admissions.php` (two audit/control
  tables). No production writes or migration before specific release approval.
- At release time recheck GitHub/AWS SHA and hashes again, preserve .env/private
  cached config and1000/day, take the runbook backup/checkpoint, apply only the
  targeted migration, rebuild caches narrowly and gracefully refresh affected PHP
  workers. Preserve the running Codex generation service and all queues/receipts.
- Verify authenticated order/dashboard behavior, source hashes, public HTTPS,
  errors and native queue state. Activation is a separate recorded configuration
  step. Proposed first activation: all active checkouts in scope (as requested), all
  six authorized actions, at most3 admissions/pass and **no daily execution cap**,
  as subsequently requested by the owner. Set `execution.unlimited=true` and
  `execution.all_checkouts=true` only with the complete activation configuration.
  Retain1000 AI decisions/day; exhausted AI quota can defer new decisions while
  monitoring and already admitted native work continue. The owner explicitly
  deferred publication; this configuration remains prepared and unapplied. Never
  equate scans of all orders with forced execution of held/unsupported work.

## Implementation evidence

Prepared gateway, six native adapters, persistent new-admission stop, scoped order
monitoring and the existing order activity timeline are complete in the feature
branch. PHP8.3 regression suites, isolated MySQL concurrency checks,15 real-CLI
isolation probes and actual Blade/RTL rendering are recorded in
[the milestone report](docs/autopilot-controlled-execution-2026-11-04.ar.md).
No AWS application/config/schema/queue/order mutation was performed in this turn.
At11:09:15UTC the actual dedicated worker identity reported ChatGPT provider ready,
Shadow evaluation active,545/1000 attempts and execution disabled; the new schema
was absent. Reading health as www-data is not authoritative because the private
socket belongs to the dedicated worker security boundary.

## Historical Phase 1 record

# HeroKid Auto Pilot — Phase 1

## Current-memory reevaluation COMPLETE; 1000/day LIVE — 4 November 2026

The owner explicitly raised the permanent allowance to **1000 decisions/Cairo day**.
Verified on AWS and the authenticated dashboard at11:49 Cairo. Shared usage was
300 before the new local benchmark; all89 attempts were reconciled to389 before
resuming the dedicated Shadow worker. A later live snapshot was395/1000. No reset,
separate allowance, API fallback, autonomous execution or new AWS resource.

Dedicated branch `codex/autopilot-memory-reevaluation-20261104` starts from verified
AWS/GitHub source `6421694bdeb9df3734188a7eec5e4de9fbd4bdbc`. Offline evaluation
changes remain on this feature branch; production application source is unchanged.
Only cached `agentic.daily_limit` changed; environment, all other configuration,
permissions and existing production ownership were preserved.

- **Actual isolated Codex result:89/89 correct,100%** on the37 approved checkouts;
  unsafe proposals0, false waits0, false completions0, unevaluated targets0.
  Unsupported targets19;24 existing-automation comparisons,0 differences.
  All89 responses used ChatGPT authentication, zero tools and verified isolation;
  every policy kept execution disabled. Independent label/metadata verification passed.
- Approved v3 corpus unchanged. Actual global memory versions `1:2,2:1,3:1,4:1,5:1,6:1`
  overlay only guidance/fingerprints; reports preserve original/effective corpus and
  memory hashes. No expected labels or captured business facts were rewritten.
- Premature completion now counts as an unsafe proposal before policy and is also
  reported as `false_completions`. Rejected proposals remain failures in metrics.
- Three actual anonymized rejected completion cases are preserved separately with
  their old proposals and policy reasons. Their proposed labels follow the owner's
  confirmed rule; approval remains `pending_owner_review`, outside the89 denominator.
  [Arabic review](docs/autopilot-live-delivery-review-2026-11-04.ar.html).
- Before changes:313 tests/4199 assertions on isolated SQLite. After:317/4375 on
  SQLite,317/4376 on isolated MySQL8.4; final malformed-input coverage12/325.
  PHP8.3/Pint/diff checks pass. No production tests or migrations.
- Authenticated activity/quota/memory/history were visually verified. Observation
  continued at the old cap and Shadow decisions resumed under1000. Six active rules,
  seven immutable versions retained; all three earlier blocked proposals retained.
  Current-memory live cohort207/207 agrees with registry with0 new policy blocks at
  11:48:53 Cairo; this separate live observation is not approved-set accuracy.
- Recovery `/home/ubuntu/herokid-before-autopilot-1000-20261104`: prior private config,
  hashes/modes, quota receipt, anonymized report and service/log baselines. Preserve
  consumed attempts and later writes during any recovery. No shared cache/queue reset.

[Measured report](docs/autopilot-memory-reevaluation-2026-11-04.ar.md),
[actual model results](docs/autopilot-memory-reevaluation-2026-11-04.json), and
[validation evidence](docs/autopilot-memory-reevaluation-validation-2026-11-04.json).

## Initial AWS deployment history — 4 November 2026

The owner explicitly authorized deployment. Application `be5fe8b461e1bbd01bc3ebb93d9534f2117c638a`
is deployed from GitHub main on the existing AWS host. The three exact additive
migrations are applied and observation is enabled. Dedicated AWS ChatGPT device
authentication, actual container/network attestation and zero-tool health passed.
**Shadow evaluation started at 06:40:51 UTC; autonomous execution remains absent.**
The owner then explicitly raised the effective allowance to **300 decisions/Cairo
day**, preserving the 89 local acceptance attempts and all subsequent AWS attempts.
Dashboard/activity updates work without AI. MCP remains disabled; no new AWS resource.

The first ten AWS proposals included three incorrect completion proposals caused
by interpreting raw order `delivered` as customer delivery. Hard policy blocked all
three. After draining only the Shadow worker, the trusted operator clarified the
owner's existing memory rule with explicit field meanings, using the existing admin
action and immutable revision 2. Six rules now have seven versions; all rejected
decisions remain in audit. A real re-evaluation of the same rejected checkout used
revision 2 and correctly returned `human_required/FULFILLMENT_REQUIRES_TEAM`.
Shadow scheduling resumed at 06:56:15 UTC. This small live verification is not a
new full approved-dataset accuracy result; expand coverage before any live-execution gate.

The existing private configuration was preserved, with only Auto Pilot settings,
its dedicated queue and `App\Providers\AgenticServiceProvider` added. The cached
provider registration is essential: Laravel does not merge `bootstrap/providers.php`
when configuration is already cached. The actual scheduler now lists and runs
the bounded observer. Current evidence, limits and recovery are recorded in
`docs/autopilot-aws-deployment-2026-11-04.ar.md` and the AWS runbook.

## Owner review and memory/activity amendments — 3–4 November 2026

- Product name: **HeroKid Auto Pilot**. Preserve technical route/config names for compatibility.
- The owner returned all 37 reviews and subsequently confirmed that **actual customer
  delivery in shipping status**, or order cancellation, ends the lifecycle even with
  an old manual hold. The order status labelled "production approved" stays open.
  Apply this only
  to Auto Pilot projections/decisions; never rewrite existing business statuses or
  ownership. Mixed checkouts still evaluate each remaining live unit independently.
- Preserve v1 and the original reviewed v2 with their reports. Publish v3 for the
  latest explicit delivery clarification: 37 checkouts / 89 targets; only cases
  1, 32, 33, 36 and 37 change expectations, with captured facts unchanged.
  Keep mock and real Codex scores distinct. The full v3 real Codex evaluation passed
  on 4 November: 89/89 correct, zero unsafe proposals/false waits/unevaluated targets.
- Add persistent, encrypted, scoped decision memory. Authorized staff can create,
  review, edit, archive and restore rules, with optimistic revisions and immutable
  version history. Decision feedback links to the exact run. Automatic suggestions
  stay drafts; only explicit staff activation supplies guidance to the Decision Agent.
- Store only approved, bounded guidance in sanitized decision snapshots. Include
  applicable memory versions in context fingerprints and audit history. Memory edits
  invalidate affected observations without making AI calls or altering business data.
  Guidance cannot grant tools, alter isolation, infer payment, or bypass hard policy.
- Seed the owner's reviewed operating lessons through an explicit idempotent import.
  Upgrade the two untouched old delivery/hold rules as new immutable revisions.
  A manually edited legacy rule requires visible review; never silently overwrite
  it. Keep later staff edits after a current rule is imported. Retain source references;
  do not execute the supplied HTML or import its scripts.
- Add a current-activity panel: actual stage, checkout, remaining/selected target,
  start/update times, provider wait, quota/disabled/offline reasons, last outcome and
  expired/stalled worker state. Refresh independently of AI; never invent progress.
- Baseline existing automation before edits, then test memory permissions, revisions,
  rollback, applicability, stale in-flight decisions, terminal/mixed checkouts,
  manual staff actions and current activity on isolated SQLite/MySQL.
- Keep source observation/evaluation/MCP defaults OFF and no execution controls.
  AWS was reverified read-only at 22:10 UTC
  on 3 November (4 November Cairo): `99c0b981a37a4828f5c7fbafe85a96d5d2d574b1`.
  The dedicated release branch includes that base; recheck any intervening drift
  before the subsequently approved deployment. Preserve the dirty primary checkout.

Status: architecture and amendments implemented; approved real evaluation and
memory acceptance passed, **Shadow Mode only**. See the current AWS status above.
The November 2 no-deployment boundary was superseded only for this explicit AWS
release. Customer messaging, production generation and autonomous execution by
Auto Pilot remain unauthorized and unimplemented.

## Baseline and scope

- Dedicated branch: `codex/agentic-shadow`, isolated checkout. Base verified on
  AWS: `683389a1f2071fa997b6fdbb9f6f37fc96f4028b` on 2 November 2026.
  During implementation AWS advanced to `77ef1cc00094aced96bcb1db500480d87b40a971`;
  its cover/preview-state release was inspected and merged locally before final
  verification. Revalidate again before any deployment.
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
- The November 4 explicit authorization covers this AWS application release,
  its three additive migrations, isolated host runtime and Shadow operation.
  No new recurring AWS resource is authorized. Later releases still require scope
  and runbook checks; deployment approval never grants autonomous execution.
- Later execution phases require separate review of ownership transfer, one
  capability/cohort at a time, idempotency and the approved real evaluation gate.

## Prepared implementation status

- November 4 lifecycle amendment: production approval remains open until customer
  delivery/cancellation. Before the amendment, SQLite passed 496/3,866; the same
  candidate's earlier MySQL baseline also passed 496/3,866. After the amendment,
  **503 tests / 3,988 assertions passed on each isolated SQLite and MySQL 8.4**.
  The approved v3 real evaluation passed **89/89 (100%)** across 37 checkouts:
  unsafe proposals 0, false waits 0, unsupported targets 19, unevaluated targets 0,
  comparable existing admissions 24 and differences 0. All 89 calls used ChatGPT
  subscription auth, zero tools and the attested private runtime; execution stayed
  disabled. Same persistent quota, natural Cairo-day rollover, **89/100** attempts
  on November 4; no reset or limit increase. Historical v1/v2 evidence is unchanged.
  See `docs/autopilot-lifecycle-clarification-2026-11-04.ar.md`,
  `docs/autopilot-lifecycle-validation-2026-11-04.json` and
  `docs/autopilot-evaluation-actual.v3-codex.json`.
- Release integration on November 3: candidate branch
  `codex/autopilot-shadow-release-20261103` is based on read-only verified AWS
  `99c0b981a37a4828f5c7fbafe85a96d5d2d574b1`, preserving its assignment permissions,
  durable pause controls, staff preview approval and book prompt lineage. This is
  the reviewed preparation baseline, subsequently deployed as recorded above. Details and exact gates are in
  `docs/autopilot-release-preparation-2026-11-03.ar.md`.
- Before integration, 435 tests / 3,348 assertions passed on each isolated PHP 8.3
  SQLite and MySQL 8.4 database. After integration, 496 / 3,866 passed on each;
  existing worker Python 26, order frontend 9 and bridge Node 15 also passed.
- The amd64 runtime now uses a private, gateway-isolated Docker network and a
  separate CONNECT-only subscription proxy. Actual Linux hostile-tool probes,
  network denials and one real subscription decision passed. Cumulative local
  attempts are 94/100 on November 3 Cairo; the original 87-case score is historical
  v2 evidence, not a new full benchmark of this candidate.
- **Business rule confirmed:** the owner explicitly approved continued tracking
  after production approval until actual customer delivery or cancellation.
  The implementation uses shipping/order status behaviours, including configured
  aliases; legacy checkout/payment/printing completion cannot hide open fulfilment.
  Approved production is not regenerated. Unstarted book interiors retain the
  existing read-only admission fence, cover/payment/link checks and executor preflight.
  Memory v2 and evaluation corpus v3 preserve all historical originals. See
  `docs/autopilot-lifecycle-clarification-2026-11-04.ar.md` for this amendment's evidence.
- Docker 29.9.3 and Compose 5.7.0 are installed on AWS after explicit approval;
  the exact image pair and three additive migrations are installed, and bounded
  observation and Shadow evaluation are enabled. Dedicated host authentication,
  actual container/network attestation and zero-tool checks passed on November 4.
  The owner initially raised the host allowance to300/day, then to1000/day as recorded above, without resetting usage.

- November 3 real acceptance: dedicated ChatGPT authentication verified through
  actual inference; **87/87 correct (100%)** on 37 owner-approved checkouts,
  unsafe proposals 0, false waits 0, unevaluated targets 0, unsupported targets 19,
  comparable existing admissions 22 and differences 0. The approved accuracy gate
  passes; this does not enable live execution or establish general-case accuracy.
- Real memory acceptance passed three decisions: priority revision 1, changed
  priority revision 2, then restored guidance as revision 3. Decisions used the
  current context, history remained intact, and synthetic business orders were
  unchanged. Memory is editable guidance, not model-weight training.
- All real calls used the same local quota: **93/100 attempts** on 3 November in
  Cairo, including two refused startup attempts, one successful smoke, 87 approved
  targets and three memory decisions. No quota reset, API fallback or model tools.
- Runtime compatibility fixes preserve isolation: disposable CLI bookkeeping,
  read-only credential reference, supported built-in provider configuration and
  safe stream cleanup. Final regressions passed 384 PHP tests / 2,518 assertions,
  14 Node tests and Linux isolation acceptance. See
  `docs/autopilot-real-validation-2026-11-03.md` and its real JSON reports.
- November 3 real-provider preparation: corrected the pinned CLI's login-status
  arguments, kept the read-only credential boundary, added system HTTPS trust
  certificates, and made benchmark provider outages stop further calls while
  retaining every unevaluated target. See
  `docs/autopilot-subscription-validation-2026-11-03.md` for measured evidence.
- Implemented locally: context/specification/registry, bounded decisions and
  scheduling, policy simulation, encrypted audit, isolated fail-closed SDK bridge,
  dashboard, actual/synthetic evaluation harness and seven read-only MCP tools.
- November 3 extension: HeroKid Auto Pilot naming, owner-reviewed terminal rules,
  editable scoped memory with immutable revisions, and live activity/status polling.
  See `docs/autopilot-memory-activity-implementation.md` for this amendment's evidence.
- Existing production execution/ownership service diff against the merged release
  is empty. Defaults remain off; the gateway has no execute method.
- Historical first owner review remains in `actual-orders.v2.json`: 37 checkouts,
  87 targets. The latest delivery clarification is the separately versioned v3
  with 89 targets; actual customer delivery takes precedence over an old hold.
- AWS Shadow activation is complete; no further device login is currently needed.
  Live autonomous execution remains unimplemented and unauthorized. The earlier
  approved local v3 score is 89/89; initial AWS completion errors were separately
  blocked and retained. Revision 2 clarified the owner's existing semantics and
  passed one same-case real decision. The broader revision-2 accuracy gate is not
  claimed complete. New live scenarios must join a reviewed regression set before
  any future execution approval; preserve all failures and independent evaluation.
- Detailed files, measured tests, known SQLite limitations and runtime boundaries:
  `docs/agentic-shadow-implementation.md`, `docs/agentic-shadow-architecture.md`,
  and `ops/agentic/README.md`.

Prepared unlimited-execution amendment: PHP8.3 isolated SQLite regression passes480tests/5850assertions; focused execution baseline13/93, after15/120. No production changes; earlier MySQL/isolation evidence predates this amendment.
