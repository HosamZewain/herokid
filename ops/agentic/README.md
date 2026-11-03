# Isolated Codex decision bridge

Prepared for Shadow Mode, **not installed or authenticated on AWS**. The PHP
application has no CLI launcher or credential reader. It talks to this separate
runtime through one Unix socket. There is no execution endpoint.

The pinned official TypeScript SDK and CLI are 0.166.0. The SDK launches `codex
exec`; it is not Codex Desktop. `codex-entry.mjs` adds the CLI's supported
`--ignore-user-config --ignore-rules --ephemeral` switches because this SDK does
not expose them as thread options. The model sees no tools, MCP servers, images,
raw prompts, customer messages or local application source. Shell, apps, network
tools, hooks and subagents are disabled; read-only sandbox and never-approve are
fixed. A local fake provider checks the actual advertised tools at startup.

The Node process is trusted infrastructure that launches one fixed CLI; the
Decision Agent has no shell capability. Streaming tool/file/network items are
also rejected and the CLI aborted. The post-check is defense in depth, not the
only isolation boundary.

## Authentication and fail-closed operation

Only the official **ChatGPT subscription login** is admitted. A dedicated account
session must be provisioned separately using the official CLI login flow and
reviewed for the owner's subscription/account restrictions. Do not copy the
desktop home or automatically log in using existing production-worker tokens.
No API key, provider URL, inherited environment, user config, login credential or
account identifier is supplied by PHP or the order. There is no API-key or mock
fallback. Usage/rate-limit/auth failures stop the pass and remain visible.

`/run/agentic-auth` must be a read-only directory containing only the dedicated
`auth.json`. The runtime checks subscription auth mode and fixed CLI `login
status`; it never logs tokens. With read-only credentials, refresh/expiry can
require an operator to renew that dedicated session. An expired session can
still look locally logged in: the actual model call then fails closed. Real
account entitlement and successful inference remain an explicit validation gate.

The login-status command uses `forced_login_method="chatgpt"` and
`cli_auth_credentials_store="file"`. Unlike `exec`, `login` in CLI 0.166.0 does
not accept `--ignore-user-config`; the credential directory is instead restricted
to the single auth file. Only the pinned CLI's exact read-only PATH-helper warning
is tolerated alongside `Logged in using ChatGPT`. Unknown output or a nonzero
exit refuses the runtime. A locally parseable session is not proof of a current
subscription entitlement; only successful real inference can establish that.

The image installs Debian's `ca-certificates` for the native CLI's HTTPS trust
store. Node's bundled roots alone are insufficient for the CLI login client.
TLS certificate verification is never disabled.

Inference uses a fresh CLI home and a separate empty work directory inside the
bounded `/tmp` mount. The native CLI needs writable bookkeeping state even with
`--ephemeral`. Its temporary `auth.json` is a symlink to the fixed read-only
credential mount, not a credential copy. The reference must still be that exact
symlink before a result is accepted; replacement/refresh in scratch rejects the
result. The entire scratch tree is removed after each attempt, without following
the credential link. History persistence is disabled. This grants no file tool
to the model and adds no application, host-home or secret mount.

Use the built-in `openai` provider with forced ChatGPT authentication. CLI 0.166.0
rejects any `model_providers.openai` override; the former retry override was
invalid. The bridge makes one SDK decision attempt, aborts on a provider error,
and enforces an 80-second total deadline. Native internal transport retries are
not claimed to be zero; they remain inside that deadline and do not create a new
application decision cycle or an API-key fallback.

The streaming guard tolerates only the pinned CLI's exact diagnostic confirming
that its code-mode host is disabled. All other unexpected item types and errors
are rejected. Rejection aborts while the SDK's child-process listeners are still
attached. There is no second abort after SDK iterator cleanup, which otherwise
can crash SDK 0.166.0's caller with an unhandled `AbortError`.

OS acceptance requires Linux, non-root UID, zero effective capabilities,
NoNewPrivs, seccomp filtering, read-only root, an exact mount allowlist and no app
code, `.env`, host home or Docker socket. Writable scratch and socket directories
are bounded. Mount checks cannot attest a compromised host/container engine;
deployment must also review the image digest, process ownership and launch flags.

## Reproducible local acceptance (synthetic; no login/provider traffic)

Build this directory's Dockerfile. Run the image with `--network none --read-only
--cap-drop=ALL --security-opt=no-new-privileges --pids-limit=128 --memory=512m`,
`--tmpfs /tmp:rw,noexec,nosuid,size=128m`,
`--tmpfs /run/bridge:rw,noexec,nosuid,uid=1000,gid=1000,mode=0700`, and an **empty**
directory mounted read-only at `/run/agentic-auth`. Command: `node self-test.mjs`.
No privileged/host network/host PID/host IPC options are permitted.

`npm test` additionally tests clean environment, rejected API authentication,
stream failures, tool inventory and hostile shell/read/patch responses against
the real CLI and a loopback fake provider. Custom apply_patch is rejected by the
read-only sandbox/approval policy, even if a hostile provider emits an unadvertised
call. Sentinel contents stay unchanged and are never returned to the provider.

The suite also validates the complete production configuration through the real
CLI, linked credentials with the production output schema, immutable credential
references, exact-warning handling and stream cleanup ordering. A separate
network-disabled synthetic-session acceptance run exercises the exact built-in
provider configuration and verifies that provider failure leaves the process
alive, the runtime idle and the decision refused.

The prepared authenticated profile is now in `deploy/compose.yaml`; see
[`deploy/README.md`](deploy/README.md). It requires Docker 28+, an internal
gateway-isolated network and a separate proxy allowing CONNECT to the two fixed
subscription hosts on port 443. The model container has no default route, published
port or host gateway. The runtime rejects direct IPv4/IPv6 default routes, while
the host validator checks exact image, launch, mount and network boundaries.
The SDK environment fixes the proxy and never inherits proxy overrides. Empty-auth
acceptance and one real subscription inference passed on the amd64 candidate;
network-none remains useful for synthetic tests. No AWS installation has occurred.
Never mount application code, credentials or the Docker socket into this runtime.

Official references checked during implementation:
- [Codex SDK](https://learn.chatgpt.com/docs/codex-sdk)
- [Authentication](https://learn.chatgpt.com/docs/auth)
- [Configuration reference](https://learn.chatgpt.com/docs/config-file/config-reference)

## Scheduling and recovery

Defaults in `config/agentic.php` disable observation, evaluation and MCP. Enabling
observation later does not enable evaluation. Observation is a bounded scheduled
read projection; evaluation uses its own Redis connection/queue `agentic-shadow`,
one unique job, one global database lease and one provider call at a time. It
does not consume the production `default` or `codex-scenes` queue. Worker timeout
1000s is below the database lease 1100s and retry_after/unique TTL 1200s; individual calls are bounded to 90s
(bridge model deadline 80s), and the pass is capped at ten cycles.

Every attempted decision consumes the configurable Cairo-day quota, including
failed calls. Three cycles per pass is the default. Coverage is by current
context version and target; prior proposals never become assumed facts. Partial
passes yield for at least a minute. Every third pass admits oldest attempted or
never-evaluated work without blocked-first priority. Raising quota resumes
deferred work immediately. Provider errors/unknown outcomes require investigation
or a genuine context change, not blind retries. Expired shadow leases mark only
shadow runs uncertain; they never clear production leases or retry generation.

Local benchmark evaluation also stops its provider calls after an unavailable or
uncertain provider result. Its report keeps every remaining target in the
denominator and marks skipped cases explicitly. Invalid model decisions are
scored as failures while later cases remain eligible for evaluation. A partially
evaluated corpus cannot return a successful benchmark exit, even if completed
answers would exceed 95% correctness.

Model saved/deleted hooks provide coalesced after-commit hints. Every five minutes
a cursor scans up to 50 active checkout groups plus up to 50 known groups that
left the active set. Query-builder writes, catalogue/config changes and missed
events are therefore eventually observed. Full sweep latency depends on active
backlog, and is shown separately from evaluation freshness. Polling progress
does not change decision fingerprints while an existing run remains active.

Future live execution requires a separate approved gateway implementation:
authenticated actor; fresh context and existing executor preflight; sorted
checkout/unit locks; unique durable action reservation keyed by inputs/spec;
transactional outbox; existing service dispatch; operation receipt; unknown-result
reconciliation. None of these actions is implemented or callable in Phase 1.
