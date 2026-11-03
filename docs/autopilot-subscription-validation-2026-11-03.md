# HeroKid Auto Pilot — subscription validation preparation

3 November 2026. Dedicated branch `codex/agentic-shadow`, starting at
`bc58a21e642e29ca59eac95b5790d66f8ba0e5bd`. Prepared locally in Shadow Mode.
No AWS access, deployment, production migrations, business execution or account
credential reuse was performed in this validation step.

## Findings and changes

The real pinned CLI exposed two gaps that the earlier synthetic runtime tests
did not exercise:

1. `--ignore-user-config` is an `exec` option in Codex CLI 0.166.0, not a global
   `login` option. The old authentication check would reject valid sessions.
   Login status now uses the supported forced-ChatGPT and file-store overrides.
   The dedicated read-only credential directory must still contain only
   `auth.json`; the environment is still an explicit allowlist. `exec` retains
   its config/rules/ephemeral isolation switches.
2. The slim Node image lacked the system CA certificate bundle needed by the
   native CLI. Installing Debian's `ca-certificates` enabled the official device
   login endpoint. TLS verification remains enabled. No API key was supplied.

The CLI also emits a PATH-helper setup warning on a read-only credential mount.
The verifier admits only the exact pinned warning plus the exact ChatGPT status;
unknown output, a nonzero exit, API authentication, extra credential-directory
entries or unverified OS confinement still fail closed. A local login status is
not proof of valid subscription entitlement; successful real inference remains
required.

The evaluation runner now stops calling the provider after a provider exception,
including an uncertain result or exhausted quota. Remaining cases/targets remain
visible and count in the full accuracy denominator. Invalid returned decisions
are scored separately and do not conceal subsequent cases. The command returns
failure if any target is unevaluated, even when nominal correctness exceeds 95%.

Changed files for this milestone:

- `ops/agentic/Dockerfile`, `isolation.mjs`, `self-test.mjs`,
  `test/isolation.test.mjs`, `README.md`.
- `app/Services/Agentic/EvaluationRunner.php` and
  `app/Console/Commands/BenchmarkAgenticDecisions.php`.
- `tests/Feature/AgenticEvaluationTest.php`.
- `PLAN.md`, this report, and the new explicitly labelled Mock evaluation report.

## Verification

| Check | Before | After |
|---|---:|---:|
| Existing automation plus Auto Pilot regression selection, PHP 8.3 / isolated SQLite | 381 tests / 2,504 assertions | 384 / 2,518 passed |
| Node SDK/CLI tests, image dependencies | 7 passed | 9 passed |
| Linux non-root/read-only/no-capabilities/seccomp acceptance | Earlier milestone passed | Passed, including 6 hostile tool probes and the CA bundle check |
| Positive local credential-format check with synthetic invalid tokens, network disabled | Broken login arguments | Accepted format under the read-only mount; no entitlement claim and no inference |
| Empty credentials, application secrets, filesystem writes, shell/read/patch calls | Rejected | Rejected |
| Approved actual v2 corpus, explicitly Mock | 87/87 | 87/87; unsafe 0; false waits 0; unsupported 19; missing 0; comparable legacy 22, differences 0 |
| Real subscription inference and real memory correction | Not performed | Pending completion of the dedicated sign-in |

SQLite had no network and no production environment/configuration or customer
media. This step changes no database queries/schema; MySQL was not rerun here.
The preceding memory/activity milestone passed 381 tests on isolated MySQL.

The first host `npm test` attempt could not find the nested SDK dependency. Tests
were run using the pinned image dependencies instead. An initial Docker build
needed permission to update local builder metadata; it then succeeded. These
setup attempts are not counted as passed tests.

The approved dataset was not changed. Its SHA-256 remains
`c3b9aef60b21524617d01a2d50ecf43b51a2481030f0ca1d43bb3c70e68a43c1`.
The new Mock report is `autopilot-evaluation-actual.v2-auth-hardening.mock.json`;
it explicitly leaves `accuracy_gate_met=false` and `live_execution_gate=false`.

Test image: `herokid-agentic-bridge:auth-verification`, image ID
`sha256:eedbe3735e9d2421c2ce81eaf2b34ef3e00a0909222a323534d3052f371f21b9`.
Local logs: `/private/tmp/herokid-autopilot-evidence/auth-*.log`.

## Remaining acceptance

The official device-login flow was initiated for a new dedicated local session.
No desktop or production-worker credentials were copied. At this report's
checkpoint the local acceptance database records **0 real decision attempts**.
Authentication codes/tokens/account identifiers are intentionally absent from
this report and Git.

After sign-in: seal only the newly provisioned auth file in the dedicated
read-only mount, verify health, run one approved smoke case, then all 87 approved
targets. Use the same local quota database for every real attempt, including
failures; retain the configurable 100/day ceiling. Stop on provider uncertainty.

A local synthetic memory acceptance script is prepared in the separate acceptance
directory. It creates two independent synthetic units, requests a decision with
memory revision 1, changes the priority in revision 2, then restores revision 1
as revision 3. It checks the selected target, policy result, exact memory
revision, immutable history and unchanged business orders. These three calls
also use the same quota. This demonstrates retrieved, versioned guidance, not
model-weight training. The real test has not run yet.

The ≥95% real-model gate and absence of unresolved safety-critical model failures
remain unverified. Local authentication is not an AWS installation. Defaults
remain off, the gateway has no execution method, and deployment/autonomous
execution still require separate authorization.

Official reference consulted: [OpenAI authentication documentation](https://learn.chatgpt.com/docs/auth).
