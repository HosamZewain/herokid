# Agentic Shadow implementation evidence

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
