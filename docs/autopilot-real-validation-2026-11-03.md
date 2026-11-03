# HeroKid Auto Pilot — real subscription validation

3 November 2026. Local dedicated branch `codex/agentic-shadow`, starting from
`b07f3ed4acdcdd90f1eb9e75cc1b4d8253aecb81`. Shadow Mode only. No AWS access,
deployment, production migration or autonomous execution occurred in this step.

## Authentication and runtime findings

The owner completed the official device login for a new dedicated ChatGPT
session. Only that newly provisioned session was sealed into the read-only
credential volume. The temporary provisioning volume was removed. No desktop,
production-worker or application credentials were copied. The PHP evaluator
receives only the Unix socket, never the credential volume.

The first two counted attempts returned no accepted decision. Offline tests with
synthetic credentials and no network reproduced these compatibility problems:

- CLI 0.166.0 rejects overrides of its reserved built-in `openai` provider ID.
  Removed the invalid retry override and retained the official built-in provider
  with forced ChatGPT authentication. The bridge has an 80-second total deadline
  and no application retry loop or API-key fallback. Native internal transport
  retries are not asserted to be zero.
- A wholly read-only CLI home cannot initialize the native in-process app server.
  Each attempt now gets a temporary CLI home and separate empty work directory
  under the bounded `/tmp` mount. Its auth file is only a symlink to the fixed
  read-only credential file. Replacing that reference rejects the result. The
  scratch tree is removed after the attempt; history persistence is disabled.
- The CLI emits one diagnostic confirming that code mode is unavailable because
  its host is disabled. The stream guard tolerates only that exact pinned
  diagnostic; unexpected item types and other errors still fail closed. Code
  mode, shell, filesystem tools, apps, hooks and subagents remain disabled.
- SDK 0.166.0 removes child-process listeners during iterator cleanup. Aborting
  again afterward can crash its caller. Rejection now aborts while the iterator
  is active; final cleanup clears the deadline and lets the SDK close its child.
  Offline provider failure now leaves the process alive and the runtime idle.

These are trusted CLI bookkeeping changes, not new model capabilities. The root
filesystem and credential mount remain read-only; the process remains non-root,
without effective capabilities, with NoNewPrivs and seccomp. No application
source, `.env`, host home, Docker socket or application secret enters the runtime.

The third counted attempt completed successfully through the actual subscription.
For the reviewed delivered-plus-old-hold case, it returned `completed` with
`LIFECYCLE_COMPLETE`. Policy allowed the shadow proposal and explicitly denied
execution; metadata recorded `authentication=chatgpt` and `tools_used=0`.

## Final real acceptance results

The approved corpus completed through the isolated subscription provider:
**87/87 correct (100%) across 37 actual, anonymized checkout scenarios**.

| Measure | Result |
|---|---:|
| Correct decisions | 87 / 87 (100%) |
| Unsafe proposals before policy filtering | 0 |
| False waiting decisions | 0 |
| Unevaluated targets | 0 |
| Correctly identified unsupported targets | 19 |
| Comparable existing automation admissions | 22 |
| Differences on those comparable admissions | 0 |
| Approved accuracy gate | Passed |
| Live execution gate | False |

Unsupported targets were explicitly identified and scored against the approved
expectations; they were not skipped. Every result records ChatGPT authentication,
zero tools and policy `execution_allowed=false`. No safety-critical failure remains
unresolved on this evaluation. The dataset was unchanged; its SHA-256 is
`c3b9aef60b21524617d01a2d50ecf43b51a2481030f0ca1d43bb3c70e68a43c1`.

The separate real memory acceptance also passed. Two synthetic, independently
eligible units were presented with checkout-scoped approved guidance:

| Memory revision | Expected first target | Actual first target | Context version |
|---|---|---|---:|
| 1 | `product:1` | `product:1` | 2 |
| 2, edited priority | `product:2` | `product:2` | 3 |
| 3, restoration of revision 1 | `product:1` | `product:1` | 4 |

All three proposals passed policy with execution denied. The memory history
retained revisions 1, 2 and 3, the context fingerprints changed, and the synthetic
business-order rows remained unchanged. This demonstrates retrieved, versioned
guidance affecting real decisions; it does not claim model-weight training.

The same dedicated local SQLite quota database counts every real attempted
decision, including the two startup failures. The configured limit remains 100
per Cairo day. Neither the database nor the quota is reset between smoke,
benchmark and memory tests. Final usage on 3 November: **93/100 attempts** = two
refused startup attempts + one successful smoke + 87 corpus decisions + three
memory decisions. The two startup failures returned no accepted model result and
are retained outside the completed corpus score. Final lease was released, stage
was `idle`, provider error was null, and last outcome was `benchmark_finished`.

Auditable reports:

- [Approved real corpus](autopilot-evaluation-actual.v2-codex.json).
- [Real memory edit and restore](autopilot-memory-real-2026-11-03.json).
- [Successful delivered/old-hold smoke](autopilot-smoke-real-2026-11-03.json).

Report integrity checks verified all expected rows, current memory versions,
authentication, zero tools, denied execution, final quota and absence of common
token/email patterns. Reports contain anonymized cases or synthetic units only.
After acceptance, the idle local bridge container was stopped. The dedicated
credential remains only in its local protected volume for later authorized use;
no scheduled evaluator or autonomous execution was started.

## Regression and isolation evidence

| Check | Result |
|---|---|
| Existing automation and Auto Pilot baseline at the starting commit | 384 PHP tests / 2,518 assertions; previous isolated SQLite run |
| Same regression selection after runtime changes | 384 tests / 2,518 assertions passed, PHP 8.3.3, network-disabled SQLite |
| Node SDK/CLI baseline | 9 tests passed |
| Final Node SDK/CLI suite | 14 tests passed |
| Linux confinement | Passed; 6 hostile shell/read/patch probes; root writes denied; no application secrets; unverified auth refuses inference |
| Complete real CLI configuration | Regression reproduced reserved-provider rejection before correction; passed after correction |
| Exact built-in-provider startup with synthetic credentials and no network | Configuration accepted; provider result refused; process survived; runtime idle |
| Read-only credential reference | Separate non-root/read-only mount acceptance passed; reference preserved; no tools advertised |
| Stream cleanup and diagnostic handling | Premature/failure/tool events rejected; only exact disabled-code-mode diagnostic tolerated; no abort after SDK cleanup |

No application schema, business service, production worker, frontend or MySQL
query changed in this milestone. MySQL, Python worker and frontend build were
not rerun here; their preceding milestone results remain separate evidence.

Test image: `herokid-agentic-bridge:real-validation`.
Image ID: `sha256:71606f46a51dd4eef2896f45531a1641840d5664767ad6ea08c92c87055ea188`.
Evidence logs: `/private/tmp/herokid-autopilot-evidence/real-*.log` and the
explicitly named JSON acceptance artifacts. Failed setup/diagnostic attempts are
retained separately; they are not counted as passed tests or successful decisions.

## Changed files

- `ops/agentic/workspace.mjs`: disposable CLI state, fixed credential reference
  and cleanup that does not follow the credential symlink.
- `ops/agentic/decision-stream.mjs`: bounded streaming, strict event handling and
  safe SDK abort/cleanup ordering.
- `ops/agentic/runtime.mjs`, `runtime-policy.mjs`: wire those helpers into the
  existing fixed provider and preserve fail-closed credential checks.
- `ops/agentic/probe-tools.mjs`, `test/isolation.test.mjs`: actual SDK/CLI checks
  covering the real configuration, output schema, state layout and error paths.
- `ops/agentic/README.md`, `PLAN.md` and this report: operational boundaries,
  measured acceptance and remaining release gates.
- `docs/autopilot-evaluation-actual.v2-codex.json`,
  `docs/autopilot-memory-real-2026-11-03.json`,
  `docs/autopilot-smoke-real-2026-11-03.json`: anonymized/synthetic evidence only;
  no credentials, account identifiers, raw provider errors or transcripts.

## Remaining release boundaries

Real acceptance applies only to the approved dataset and the tested runtime;
it is not an AWS attestation or proof of unrestricted order automation. This is
a constrained hybrid decision provider: the deterministic capability registry
and hard policy remain authoritative. The real provider is recorded as
`subscription_default`; no exact underlying model revision is asserted.

The model reads versioned memory guidance supplied in its current context. It
cannot write memory, grant itself tools, override hard policy or train its own
weights. Human corrections/activation remain explicit and auditable.

Credential refresh/expiry, subscription limits and transport failures must fail
closed. A reference replaced during native auth refresh is rejected and needs
operator renewal of the dedicated session. An unavailable provider does not
block operational dashboard observation. The 80-second bridge deadline still
sits below the PHP request and worker/lease timeouts.

All production defaults remain off. A future release must reconcile the latest
AWS release, review installation/credential lifecycle and receive separate
deployment approval. Live autonomous execution is not implemented or authorized.

Official references: [Authentication](https://learn.chatgpt.com/docs/auth) and
[configuration reference](https://learn.chatgpt.com/docs/config-file/config-reference).
