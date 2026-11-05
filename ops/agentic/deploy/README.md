# Auto Pilot host profile — AWS Shadow evaluation active

The owner resolved the lifecycle ambiguity; see
[`../../../docs/autopilot-lifecycle-clarification-2026-11-04.ar.md`](../../../docs/autopilot-lifecycle-clarification-2026-11-04.ar.md).
The owner explicitly approved this release on 4 November 2026. Application,
additive schema, Docker and dedicated worker unit are installed; observation and
reviewed memory are active. Dedicated host ChatGPT authentication, actual container
and network attestation and zero-tool health passed; Shadow evaluation is active.
The owner increased the effective host allowance to **300 decisions/Cairo day**,
preserving all attempts. Six memory rules have seven immutable versions after an
operator clarification of the already approved delivery semantics. See
[`../../../docs/autopilot-aws-deployment-2026-11-04.ar.md`](../../../docs/autopilot-aws-deployment-2026-11-04.ar.md).
No autonomous execution exists in this
package. Do not run this as a blanket deployment script.

## Boundary and measured acceptance

The exact amd64 image pair was tested locally on Linux containers, including a
real subscription decision and the November 4 approved v3 evaluation: 89/89
correct, zero unsafe proposals/false waits/unevaluated targets, all subscription
authenticated with zero model tools. This is the earlier local benchmark. Actual
AWS activation is separately documented: initial wrong completion proposals were
blocked by policy, and the same case passed after memory revision 2. Do not reuse
the local 89/89 score as a claim about every live decision or revised memory.
The decision container has one internal network with
Docker's `gateway_mode_ipv4=isolated`, no default route and no published port.
Only the proxy joins a second outbound network. No other container may join the
private network. Docker 28+ is required; an ordinary internal network alone still
has a host bridge address and is insufficient for this profile.

Squid allows CONNECT only to exact `chatgpt.com:443` and `auth.openai.com:443`.
It rejects numeric addresses without reverse-DNS lookups, other domains,
non-443 ports, general HTTP and private/reserved destination addresses. TLS
validation stays enabled; there is no TLS interception, body/access logging or
cache. The proxy has no auth or host mounts. Do not expand its allowlist to work
around an unexplained provider failure.

The model runtime uses non-root IDs, read-only root and auth, no capabilities,
no-new-privileges, Docker seccomp, bounded noexec scratch, PID/CPU/memory ceilings
and the pinned CLI/SDK. The Docker init binary is allowed only at its observed
read-only canonical mount `/usr/sbin/docker-init`. The model has zero tools; six
hostile shell/read/patch probes confirmed no execution, sentinel changes or secret
leak. These controls assume a trusted kernel/container engine and operator.

References: [OpenAI proxy limitations](https://learn.chatgpt.com/docs/enterprise/managed-configuration#scope-and-limitations),
[Docker gateway modes](https://docs.docker.com/engine/network/port-publishing/#gateway-modes),
[Squid ACL semantics](https://www.squid-cache.org/Doc/config/acl/).

## Reviewed installation and verification sequence

1. Recheck AWS SHA, drift, free disk/memory, current jobs and actual OS. Preserve
   the existing environment, private config cache, permissions, assets, logs and
   off-instance backup/recovery checkpoint. No new EC2/service/subscription spend.
   AWS had no Docker at preparation time. Review installation of Docker 28+ and
   Compose as a host-service/firewall change; do not install an older engine or
   restart/resize production services to make room. Build/load pinned images,
   verify archive SHA-256 and loaded image IDs; never build on production.
2. Create separate non-login host users for the untrusted decision runtime and
   trusted PHP shadow worker, plus a private socket group. The auth directory is
   owned only by the runtime UID, mode 0700, auth file mode 0600. Its group must
   not grant the PHP worker access. The socket directory `/run/herokid-autopilot`
   is mode 0770, runtime owner/private group; only the dedicated worker joins the
   group. Recreate this volatile directory explicitly after reboot.
3. Provision a **new dedicated ChatGPT session** with the pinned official CLI
   login/device flow in a separate one-shot provisioning environment. The operator
   may need the owner's browser authentication. Do not reuse Desktop or existing
   production-worker credentials, put auth in the app directory, transfer it into
   this repository, or log its contents. Mount only the dedicated final auth
   directory read-only in the decision service. Expiry requires deliberate renewal.
   Run provisioning independently of SSH (a detached, temporary login container),
   so a dropped operator connection does not discard the successful login. Verify
   the final directory contains only `auth.json`; retain CLI bookkeeping outside
   the mounted directory in protected operator storage. Remove the exited login
   container after validation. Never archive device codes or credential contents.
4. Supply a private env file with the six required Compose inputs: immutable
   `AUTOPILOT_DECISION_IMAGE`, `AUTOPILOT_EGRESS_IMAGE`, numeric `AUTOPILOT_UID`,
   `AUTOPILOT_GID`, absolute `AUTOPILOT_AUTH_DIR` and `AUTOPILOT_SOCKET_DIR`.
   This is path/image configuration, not application secrets. Inspect the resolved
   Compose config before starting. Keep pull policy `never`, restart policy `no`,
   no app/source/home/Docker mounts and no ports. Images consume about 306 MB of
   combined content before Docker's storage/accounting overhead; measure disk again.
5. After explicit start, run `verify-runtime.py` against the two container names,
   private network name, exact image IDs and exact mount sources. Run the network
   acceptance script inside the container via stdin, then read bridge health.
   Host attestation, runtime isolation, zero tools and ChatGPT authentication must
   all pass. No inference is permitted on a failed or uncertain profile.
   Allow a bounded startup wait for the Unix socket: startup runs local fake-provider
   isolation probes before exposing health. This wait makes no model inference calls.
6. Application rollout keeps observation, evaluation and MCP off first. Configure
   only reviewed `agentic`/queue keys in the existing private configuration;
   do not regenerate unrelated effective production configuration blindly. Set
   `agentic.bridge_socket=/run/herokid-autopilot/decision.sock` explicitly: this
   differs from the feature's generic default `/run/herokid-agentic/decision.sock`.
   Keep provider `codex`, cycles/pass 3 and the explicitly approved daily allowance:
   the source default is 100; current AWS override is **5000**, explicitly approved and verified November 5.
   Preserve it across enable/disable operations and do not reset the attempt count.
   Discover Composer packages
   from the reviewed lockfile, then validate routes/views/cache; stale package
   discovery hid the MCP route in the first local test attempt.
   When preserving an existing configuration cache, explicitly append the new
   `App\Providers\AgenticServiceProvider` to `app.providers` exactly once, retaining
   every other provider and setting. Laravel skips merging `bootstrap/providers.php`
   when configuration is cached. Verify the actual `schedule:list` includes
   `agentic:observe` and `agentic-shadow-dispatch`, then observe a natural timer tick;
   a successful manual observer command alone does not prove scheduling or hints.
   Before enabling evaluation on the same Cairo day, carry forward the greater
   recorded local/host Auto Pilot attempt count under the runtime-state lock;
   a fresh host database must not create a second daily allowance. Preserve all
   prior quota reports. A new calendar day follows the normal quota rule.
7. Apply only the three exact additive migrations listed in the release report,
   using individual `--path` arguments after confirming their pending state. No
   blanket migration, seed, test, data rewrite, ownership change or queue replay.
   Import the current reviewed memory through the authorized application action.
   The two unchanged legacy rules gain immutable revisions. A customized legacy
   delivery rule produces a visible conflict and requires review; it is not overwritten.
8. Enable observation alone, measure bounded scans/load/freshness and verify the
   authenticated dashboard with the existing permissions. Existing production
   workers remain running; their missed new hints are covered by reconciliation.
   A five-minute scan is a bounded batch, not a promise of a five-minute full sweep.
9. Separately start the prepared dedicated PHP service and enable shadow evaluation
   only after subscription/runtime/business gates pass. The service uses only the
   `agentic-shadow` connection/queue. Timeout 1000s < lease 1100s < retry/unique1200s;
   one provider call at a time. No API-key or mock fallback, no paid generation,
   customer message, printing, payment approval or shipping execution. MCP stays
   off unless its read-only integration is specifically requested.
   The worker entrypoint changes logging to stderr in its own process so a separate
   OS user never creates/chmods the web application's shared log. Preserve the
   existing production log permissions and cached logging configuration.
10. Verify actual deployed SHA/file hashes, private config preservation, permissions,
    public HTTPS, authenticated dashboard/memory/activity, observer freshness,
    quota, new log bytes and existing worker health. Record live evidence in the
    AWS runbook only after deployment; this preparation is not that evidence.

## Stop, restart and recovery

First disable shadow evaluation; gracefully drain only its worker and current
decision. The bridge has an 80-second model deadline. Verify no live decision
before stopping the two named containers; do not terminate existing production
workers or clear any queue/lease. Observation can continue without AI, or be
disabled separately. Keep the seven additive tables and immutable history.

The bridge intentionally refuses to unlink a pre-existing socket. After confirming
the old container is stopped and no process owns its exact socket, an operator may
remove that stale socket only, re-attest and start again. No recursive cleanup.
Containers do not automatically restart after a reboot; show provider unavailable
until a reviewed startup and health check. Existing operations continue.

Any code recovery must be compatible with the latest AWS release and preserved
additive schema. No migration-down, DB restore, old-source overwrite, Hostinger
writer, queue replay, auth fallback or deletion of audit/memory/customer data.
