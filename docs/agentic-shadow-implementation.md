# Agentic Shadow implementation evidence

Historical Phase 1 evidence through November 2. The latest November 3 owner review,
HeroKid Auto Pilot memory/activity changes and before/after results are recorded in
[`autopilot-memory-activity-implementation.md`](autopilot-memory-activity-implementation.md).
The pending-owner-review statements below describe the earlier v1 only; v2 is now
owner-approved. Subsequent real Codex acceptance passed as recorded in
[`autopilot-real-validation-2026-11-03.md`](autopilot-real-validation-2026-11-03.md).
Current AWS integration and isolated amd64 acceptance are recorded in
[`autopilot-release-preparation-2026-11-03.ar.md`](autopilot-release-preparation-2026-11-03.ar.md).
The earlier unverified-auth statements below are historical. Deployment remains
unperformed. The lifecycle-label conflict was resolved by the owner's explicit
clarification; see [`autopilot-lifecycle-clarification-2026-11-04.ar.md`](autopilot-lifecycle-clarification-2026-11-04.ar.md)
for the successor implementation and evaluation, without changing historical reports.

Prepared locally, not deployed. Branch `codex/agentic-shadow`, release base
`683389a1f2071fa997b6fdbb9f6f37fc96f4028b`. Production behavior and ownership stay
unchanged. See `PLAN.md` for the approved scope and acceptance gates.

## Milestone 0 — baseline

- Plan committed as `a7ab786` before application implementation.
- Fresh Composer install from this branch's lockfile, without application scripts.
- Local PHP 8.3.3 image with GD, Imagick, SQLite and MySQL drivers. Tests have no
  production `.env`, cached configuration, credentials or media mounts.
- Existing regression selection: OrderConfirmation, OrderProcessRuntime,
  OrderProcessIdentity, ProcessPaymentReview, AutomationHandoff, PuzzleAutomation,
  ManualCodexScene, ProductReviewRuntime, SceneProgressPrecision,
  OrderProcessBuilder, OrderAutomation and ProductionAutomation.
- SQLite: **312 tests / 1,954 assertions passed**.
- Internal-network ephemeral MySQL 8.4: **312 tests / 1,954 assertions passed**.
- Fake-CLI Python worker suite: **23 tests passed**.
- Initial PHP default memory limit (128 MB) exhausted in existing GD image
  fixtures; rerun at 512 MB passed (observed peak 153 MB). Initial dependency
  bootstrap attempts before vendor installation are not counted as test results.

## Milestone 1 — context and capability foundation

Added `config/agentic.php`, the specification migration/model, ContextFingerprint,
ProductionSpecificationResolver, OrderContextBuilder, CapabilityRegistry and
AgenticContextTest. No existing execution path has been modified.

- SQLite and isolated MySQL: **9 tests / 66 assertions passed on each**.
- Tests assert SELECT-only context reads, no HTTP/queue effects, separate child and
  product keys, privacy, consistent checkout payment balances, holds, preserved
  existing outputs, unsupported capabilities, immutable specification versions,
  book component/page isolation and non-repeating active-progress fingerprints.
- Model inputs are facts/opaque references; raw names, instructions, photos,
  media paths and messages do not leave the context reader.
- Specifications restrict existing executors. New capabilities cannot enable
  arbitrary execution. Existing variant/component semantics remain authoritative.
- Proposed production still requires existing executor preflight: this read-only
  layer does not download customer media or assert fresh byte checks, provider
  billing readiness or a complete live book lifecycle. No simulator may describe
  proposal eligibility as executed or fully validated physical production.
- Remaining: scheduling/coverage, strict decision contract, isolated provider,
  dashboard, real anonymized evaluation and optional read-only MCP.

## Milestone 2 — decision, scheduling and simulation

Added ContextObserver, DecisionOrchestrator, DecisionValidator, PolicyEngine,
simulation-only ExecutionGateway, provider interface/mock/socket adapter,
AgenticServiceProvider, observation/evaluation commands, one dedicated queue job,
three audit models/four audit-control tables, and `ops/agentic` isolated SDK bridge.
Existing queue connections and business executors are unchanged. Provider wiring
and an additive queue connection are the only application bootstrap integration.

- SQLite and isolated MySQL: **22 tests / 120 assertions passed on each**.
- Node: **7 tests passed**, including six hostile unadvertised tool responses
  against the real pinned CLI and a local fake provider. Initially the test's
  rejection matcher did not recognize `aborted`/sandbox rejection; inspecting
  the synthetic feedback and asserting unchanged/unread sentinel fixed the
  test. No external inference or account credentials were used.
- Linux container acceptance passed with network disabled, read-only root,
  non-root UID, dropped capabilities, no-new-privileges and seccomp. Direct code
  write failed; application secrets/Docker socket were absent; shell/read/patch
  probes left the sentinel unchanged and undisclosed. Unverified subscription
  credentials refused inference. See `ops/agentic/README.md` for boundaries.
- Covers independent siblings across bounded passes, ready siblings alongside
  blocked units, stale decisions, duplicate events, quota rollover/increase,
  fairness, worker-loss handling, provider outage and direct-SQL lifecycle exit.
- No execution gateway method, business reservation, external job, payment write,
  production state change or activation control exists. Simulation always records
  `executed=false` and `reserved=false`; existing executor preflight remains needed.
- Remaining risks: real subscription login/inference is **not verified**; the
  real provider stays fail-closed. Container proof is local, not AWS attestation.
  Dashboard, representative actual-order corpus and its owner approval remain.

## Milestone 3 — dashboard and representative evaluation

Added AgenticAutomationController, protected GET/monitor/detail routes, three RTL
Blade views, Arabic labels and one sidebar link; no activation controls. Updated
the CSS manifest and retained all previous hashed assets. Fresh detail reads and
operational counts work independently of model availability and feature flags.
Financial amounts retain `orders.statistics.view`; no private media URLs are added.

Added EvaluationRunner, BenchmarkAgenticDecisions and a quota/lease wrapper for
future real benchmarks, plus anonymized actual and separate synthetic corpora,
reports and Arabic owner-review document. Two further checks cover shared budget
and bridge/PHP schema agreement. Native browser inspection verified the synthetic
overview/detail views; HTTP tests cover filtering, access, freshness and redaction.

- Current core Agentic tests (without MCP): **31 tests / 185 assertions**, passing
  on SQLite and isolated MySQL in the final combined run.
- Actual data: **37 checkouts / 89 manually labelled targets**, captured on AWS
  inside a read-only transaction with outbound HTTP blocked; **0 read errors**.
  Raw customer fields, media, amounts and real IDs were excluded before export.
- Mock actual-corpus result: **89/89**, unsafe proposals **0**, false waits **0**,
  unsupported targets **19**, unevaluated **0**. Existing story admission was
  comparable for 24 targets, differences **0**; these sampled targets were all
  currently non-admissible, so this is not broad production parity evidence.
- Separate synthetic corpus: **20 cases / 25 targets, 25/25**, unsafe/false-wait
  **0**, unsupported **1**. Duplicate events, stale contexts, malicious notes,
  sibling coverage and actual transport behavior are also integration-tested.
- Real Codex accuracy: **not measured**. Corpus approval is pending. Both real
  accuracy and live-execution gates remain false; 100% Mock is not an AI score.
- Production advanced to `77ef1cc` during the work. Its independent cover/preview
  changes were read and merged locally without conflict, before final regressions.

## Milestone 4 — read-only MCP

Added the official `laravel/mcp` **1.1.2** package, routes/ai.php, one server, a
shared thin ReadTool adapter, seven named tools, dedicated access middleware and
transport tests. Composer kept every previously locked dependency unchanged.

- **3 HTTP transport tests / 83 assertions** pass on SQLite and isolated MySQL.
- Tested explicit dedicated scope, wildcard rejection, inactive/missing-permission
  accounts, independent finance scope and permission, disabled feature, foreign
  Origin, cross-checkout unit lookup, extra arguments and unregistered write tools.
- Seven tools expose input/output schemas and read-only/non-destructive hints.
  Invocation repeats authorization; tools do not call the observer or provider.
- Local package discovery was required after installing with `--no-scripts`;
  the first MCP test run returned 404 until the local package manifest was rebuilt.
- MCP remains disabled and is not connected to the isolated Decision Agent.

## Final combined verification

PHP **8.3.3**, no production config/secrets/media, external requests blocked.

| Suite | Before | After |
|---|---|---|
| Existing automation selection, SQLite | 312 / 1,954 passed | **319 / 2,021 passed** |
| Existing automation selection, isolated MySQL 8.4 | 312 / 1,954 passed | **319 / 2,021 passed** |
| New Agentic tests, both engines | — | **34 / 268 passed on each** |
| Python fake-CLI scene worker | 23 passed | **23 passed** |
| Broadened MySQL run incl. admin permissions, operational cards and cover artwork | — | **389 / 2,761 passed** |

Seven added existing-automation cases come from the concurrent production release,
not removed or weakened tests. Broadened SQLite ran 388 tests / 2,749 assertions:
**two existing report tests fail because its older SQLite lacks MySQL CONCAT**.
Both failures were reproduced on an isolated checkout of production `77ef1cc`
without Agentic application changes. Both pass on isolated MySQL. No unrelated
report-query change was made to make the SQLite run green.

Vite build passed with the existing large-bundle warning. Existing dependency
advisories were reported during lockfile installation; no automatic unrelated
dependency upgrades were applied. No AWS migrations, deployment, services,
workers, customer messages, generation, payments or ownership changes were run.

Remaining acceptance gates: owner approval of the concrete corpus; a dedicated
subscription login and real inference validation inside the reviewed runtime;
real ≥95% benchmark with no unresolved safety-critical failures. Any future AWS
deployment/migration/observation enablement or live execution needs its own scoped
approval. See the architecture document and runtime README for recovery details.

## Final review follow-up

Added an explicit latest-run pointer, atomically set with attempt reservation, so
multiple decisions in the same timestamp cannot be reordered by random UUIDs.
Dashboard filters use that same pointer. The dedicated worker timeout/lease/retry
ordering is now 1000s / 1100s / 1200s. Registry version is included in context
fingerprints. A frozen-clock regression verifies the displayed latest decision.
Targeted rerun: **34 tests / 268 assertions passed on both SQLite and MySQL**.

All changed PHP files passed PHP 8.3 syntax checks. The built manifest resolves
to existing local files and contains no build-machine paths. The diff against
production `77ef1cc` for existing workflow/product/scene/confirmation services,
Order model and assignment/hold owners is empty. The original dirty UI/mobile
checkout was not edited.
