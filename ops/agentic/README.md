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

Future authenticated runtime needs reviewed outbound HTTPS for official Codex
subscription endpoints (network-none is the test profile, not an inference
profile), the same filesystem/process confinement, a private socket volume
shared only with the dedicated PHP evaluation worker, restrictive socket group,
and the exact pinned image. Never mount the application or its credentials into
this container. No such deployment is part of this change.

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
1000s is below retry_after/unique TTL 1200s; individual calls are bounded to 90s
(bridge model deadline 80s), and the pass is capped at ten cycles.

Every attempted decision consumes the configurable Cairo-day quota, including
failed calls. Three cycles per pass is the default. Coverage is by current
context version and target; prior proposals never become assumed facts. Partial
passes yield for at least a minute. Every third pass admits oldest attempted or
never-evaluated work without blocked-first priority. Raising quota resumes
deferred work immediately. Provider errors/unknown outcomes require investigation
or a genuine context change, not blind retries. Expired shadow leases mark only
shadow runs uncertain; they never clear production leases or retry generation.

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
