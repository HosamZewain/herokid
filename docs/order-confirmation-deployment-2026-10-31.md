# Order confirmation deployment and approved pilot — 31 October 2026

## Actual live state

AWS was independently verified at `4b566cfb91d3da7b8795be0716163db19222ad41` (detached), from `46f8e3e260dc6319845e1f309218340837178d70`. Owner approved deployment and the pilot, supplied a test number securely, then explicitly approved one synthetic production order. The primary dirty development checkout was not deployed.

Only the two reviewed additive migrations ran: confirmed-new status definition and encrypted conversation-message metadata. Production configuration remains **confirmation disabled, contract_ready false, legacy integration disabled**. No broad customer activation. APP_KEY and .env were verified unchanged. All 30 changed-file hashes matched. Both required Redis workers and scheduler were restored and verified healthy; other automation workers remain stopped. No new infrastructure.

Predeployment validation: 198 tests / 1,412 assertions on each isolated SQLite and MySQL, PHP 8.3 compatibility lint, production asset build and synthetic UI preview. Authenticated production confirmation panel loaded after deployment; public home, health, cart and www redirect passed.

## Deployment incident — resolved, not zero downtime

A restrictive deployment umask caused newly checked-out source files/directories to be unreadable by www-data. Nginx recorded **26 HTTP 500 responses from 11:27:33 to 11:28:20 UTC** (13:27:33–13:28:20 Cairo), an observed 47-second error interval. Only manifest-listed source files and necessary source ancestors had permissions corrected; no recursive blanket permission reset. Config/routes/Blade caches rebuilt, FPM gracefully reloaded, scoped workers/scheduler resumed. All observed later requests through approximately 11:36 UTC had no additional HTTP 5xx. A separate 11:30:44 CLI diagnostic permission error was fixed before creating the trial order.

The first migration attempt could not traverse a private home directory; it applied no schema/code change. Reviewed migration files were relocated to a www-data-readable private /var/tmp directory before the successful attempt. Do not weaken the whole home directory.

## Pilot result — blocked upstream before template send

One zero-value synthetic checkout was created: **HK10-614**, order ID **2682**, order number `TEST-ROBODESK-20261031`, checkout key `CHK-OWNER-CONFIRMATION-PILOT-20261031`. It is explicitly labelled not for production, shipping or collection. Preserve the record; no cleanup/deletion authorized.

The real SendOrderConfirmationJob ran once in the isolated existing-Redis queue `herokid-confirmation-pilot-20261031`, with process-only approved-phone allowlisting and activation boundary 2681. No persistent global configuration was enabled.

At **11:34:17 UTC / 13:34:17 Cairo**:
- GET messagesByPhone: HTTP 200.
- POST /api/conversation/close: **HTTP 500**.
- POST /api/conversation/start/sendMsg: **not called**.
- Event status `unknown`, attempts 1, no wamid/interaction receipt, queue empty.
- Order remains `new`; zero paid amount, zero Bosta shipments, zero automation runs, zero confirmation logs.

Read-only recheck at 11:35:24 UTC: HTTP 200, one open conversation, no herokid_order_confirmation template messages in the latest 50 interactions. Do not resend/requeue the unknown event automatically. RoboDesk agent needs to diagnose the close failure at the timestamp above; the owner has been asked for that upstream investigation. Actual template acceptance, delivery and inbound confirmation remain **unverified**.

Private pilot state/evidence: `/var/lib/herokid-confirmation-pilot-20261031` (0700). Private runner `/var/tmp/herokid-confirmation-pilot-20261031.php`. Do not run create again or send again: guards intentionally refuse. Do not publish the settings file or approved phone.

## Recovery

Fresh off-instance verified production backup: `herokid-20261031T112337Z.sql.gz`, local `/var/backups/herokid/`, existing S3 `private/database-backups/` prefix. Gzip integrity, local SHA256 and S3 size/checksum metadata matched before schema change. No deletion or retention change.

Private code/environment/config checkpoint: `/home/ubuntu/herokid-confirmation-before-20261031-r2` (0700). Previous code `46f8e3e260dc6319845e1f309218340837178d70`; preserve additive schema, order/status history, queue/event records and external effects if rolling code back. Do not restore old production DB, revert DNS, or retry historical jobs. Hostinger stays frozen.

## Separate UI request

Compact order-status Tags are prepared in `codex/order-status-tags` on top of the live confirmation release. They are not included in the above deployed SHA and require approval for this new production change under AGENTS.md. Links, counts and permissions remain unchanged; one horizontal row with overflow scrolling. See its implementation note for validation.
