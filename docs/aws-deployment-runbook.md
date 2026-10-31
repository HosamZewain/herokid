# HeroKid AWS deployment and agent handoff

**Canonical entry point:** [AGENTS.md](../AGENTS.md). **Last release verification: 31 October 2026, approximately 11:38 UTC / 13:38 Africa/Cairo (winter, UTC+02). Other infrastructure observations retain their stated historical dates.** This document records observations, not immutable infrastructure state. Revalidate before acting. Server schedules below use UTC; convert user-facing times with `Africa/Cairo` because its offset changes with daylight saving.

## 1. Current environment and release

| Item | Value / status |
|---|---|
| Public site | `https://hero-kid.com`; `https://www.hero-kid.com` redirects to the apex |
| EC2 / Elastic IP | `i-00fea72c8abdc72f3` / `63.188.124.6` |
| Region / zone | `eu-central-1` / `eu-central-1b` (zone from migration audit) |
| Capacity | Ubuntu 24.04, t3.medium, 30 GB gp3 EBS, approximately 4 GB RAM and 2 GB swap; no resize authorized |
| SSH | `ssh -i ~/Downloads/herokid-prod-key.pem ubuntu@63.188.124.6` |
| Application / web root | `/var/www/herokid` / `/var/www/herokid/public` |
| Runtime | Nginx, PHP 8.3 FPM, MySQL, Redis, Supervisor; application processes use `www-data` |
| Production database | **`herokid`**; MySQL and Redis bind locally according to the health audit |
| Live release verified by SSH | **`4b566cfb91d3da7b8795be0716163db19222ad41`**, detached HEAD |
| Release branch | `codex/order-confirmation`; preserved as a local ref; no claim of GitHub publication |
| Previous release | `46f8e3e260dc6319845e1f309218340837178d70` (operational cards) |
| Upstream repository | `https://github.com/HosamZewain/herokid.git`; inspect local remote configuration before pushing |
| Production identity | `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://hero-kid.com`; retain the existing APP_KEY |
| Runtime credential source | Existing protected environment/cached configuration and encrypted application settings; IAM role for AWS |

The **order-confirmation** release is live with global sending disabled; the approved single pilot is blocked by RoboDesk close HTTP 500 before sending. Recovery and the resolved deployment incident are documented [here](order-confirmation-deployment-2026-10-31.md). Compact status Tags are a separate prepared change, not yet deployed.

The **colored operational order cards + dashboard reordering** release was explicitly approved (“ارفع وانشر”) and deployed **31 October 2026, 09:37:51–09:37:57 UTC / 11:37 Cairo**, SHA `46f8e3e260dc6319845e1f309218340837178d70`. Existing order-list users now see active tags and linked count-only cards for active/unclaimed checkouts and each order status. Financial summary permissions remain unchanged; per-order invoice fields are unchanged. Every status-card count matches its filter URL; counts across different statuses may overlap for mixed checkouts. Dashboard: today, month, current all-period operations, then remaining sections; today's payment detail immediately before management notes.

Validation: **105 tests / 994 assertions passed on each SQLite and isolated MySQL**, PHP 8.3 lint and Vite build passed. Candidate/deployed read-only probes verified counts, staff financial-summary absence and dashboard ordering. Authenticated owner browser confirmed colors, a 14-checkout new-status link, and dashboard order. All nine changed-file SHA256 checks passed. Public HTTPS/home/health/cart/CSS passed; both workers running and scheduler successful, failed jobs still 81. Immediate bounded logs: 0 Laravel errors / 0 HTTP 5xx across 92 requests (29 changed admin-page requests). Narrow code checkout, graceful FPM reload and Blade cache only; no maintenance, DB/config/route cache changes, worker restart or new infrastructure. Private code checkpoint `/home/ubuntu/herokid-operational-cards-before-20261031/`; rollback code base `4127efd5d644056d09b04df2eb086d037f2679c9`, preserve data/queues/config and refresh Blade/FPM only. Documentation successor `codex/order-operational-deployment-handoff` is distinct from live code. See [implementation and deployment evidence](order-operational-cards-and-dashboard-2026-10-31.md).

The **Employee Performance report** was explicitly requested with immediate deployment and deployed **31 October 2026, 09:04:12–09:04:17 UTC / 11:04 Cairo**, SHA `4127efd5d644056d09b04df2eb086d037f2679c9`. Open `/admin/employee-performance` from Reports. Full-permission accounts, or an active canonical `owner` role plus permission-management access, can compare all/one/multiple employees. This accommodates the existing deliberate withholding of paid-automation permissions from owners; no permissions are changed. Ordinary active admins see only themselves; SQL is scoped before totals, items and undated warnings, and foreign employee IDs return 403. Financial values still require existing financial-report permissions. Defaults are current Cairo month through today. Shipped counts include delivered; delivered counts are the current delivered subset of dispatch-period orders, not a delivery-date cohort. Attribution is current assignee; unassigned orders are excluded. Values exclude shipping and deduct actual discounts. Daily tables, comparisons and single-employee SKU breakdown are included.

Validation: **126 tests / 793 assertions passed on each SQLite and isolated MySQL**; build and PHP 8.3 lint passed. Candidate and deployed read-only probes reconciled daily/employee totals, verified six ordinary staff scopes, rejected all six foreign-scope requests, and validated multiple selection/rendering. Authenticated browser verified all employees, two-employee comparison and single-employee SKU; default Cairo dates were correct. Route and Blade caches were regenerated, FPM gracefully reloaded. No migrations, maintenance, config-cache rebuild, worker restart or external actions. All changed-file SHA256 checks passed. Public HTTPS/home/cart/health/CSS passed; unauthenticated report redirects to login. Required workers remain running, scheduler succeeded, failed jobs stayed 81. Immediate observation: 0 new Laravel errors / 0 HTTP 5xx across 25 requests (four report requests). Existing source drift/untracked retained assets unchanged. Code checkpoint: `/home/ubuntu/herokid-employee-performance-before-20261031/`, including old route cache; code rollback base `77d2a21695e96f9de0e40744ef0b72ef2e5f71bf`. Regenerate route and Blade caches when rolling back, preserve DB/queue/config state. See [report and deployment details](employee-performance-report-2026-10-31.md).

The **Cairo DST report-boundary repair** was explicitly approved and deployed at **21:00:16–21:00:18 UTC on 29 October**, just after the local clock returned to 23:00 +02:00. Live SHA `77d2a21695e96f9de0e40744ef0b72ef2e5f71bf`. The shared date helper now resolves next local midnight before subtracting in UTC, so the repeated hour is included in 29 October reports (25-hour day). Sales local-end and today/yesterday analytics use the same helper. Server/PHP/MySQL and all 12 scheduled events remain UTC; display remains Africa/Cairo, with current system tzdata. No manual clock adjustment, DB changes, maintenance or worker restart. 97 tests / 609 assertions passed on each SQLite and MySQL; PHP 8.3 lint passed. Live post-transition boundary and six-employee shipping-report/render reconciliation passed, HTTP health passed, failed jobs stayed 81, and immediate logs showed no new errors. Code checkpoint `/home/ubuntu/herokid-dst-before-20261029/`; rollback base `f49361538bac2b56c2b88609002489f04b0f66df`. See [DST audit/deployment](aws-cairo-dst-readiness-2026-10-29.md). Backup remains at 02:30 UTC, now 04:30 Cairo (+ existing random delay). Do not change UTC storage or use a fixed +02/+03 offset for local report dates.

The **orders performance + shipping rounding** release was explicitly approved and deployed at **20:25:10–20:25:14 UTC on 29 October**. Live SHA: `f49361538bac2b56c2b88609002489f04b0f66df`. The order-list statistics now resolve filtered checkout keys once; discounted shipping fees round upward to whole pounds across website/mobile quotes and delivery guidance (150 with 35% discount → 98). Saved historical orders are untouched. No maintenance, schema/data migration, worker restart or infrastructure change. Graceful FPM reload, Blade cache and only the current RoboDesk guide page-1 cache invalidation were applied. Recovery checkpoint: `/home/ubuntu/herokid-rounding-before-20261029/`; code rollback base: `8021c5b8bb8f6920fd7bce9e2d4bc69089e06304`. All 28 active delivery rates passed whole-pound/bounds checks; 22 rates were rounded upward. 168 tests / 1,553 assertions passed on each SQLite and MySQL, plus 16 cart JavaScript tests. Build was unchanged after retaining historical assets. Authenticated orders reload measured ~1.73 seconds (single browser/tool wall-time sample); deployed controller+render diagnostic ~1.23 seconds. Both workers stayed running, scheduler succeeded, failed-job baseline remained 81, and the immediate observation found 0 Laravel errors / 0 HTTP 5xx over 17 requests. See [combined deployment notes](aws-orders-shipping-rounding-2026-10-29.md). No other stabilization work was deployed.

The previous SKU-breakdown enhancement was explicitly approved and deployed at **20:02:25–20:02:29 UTC on 29 October**. Live SHA: `8021c5b8bb8f6920fd7bce9e2d4bc69089e06304`. The employee product/story table aggregates quantities and discounted net values across all selected orders, keeping variants separate. Individual orders are collapsed under `عرض تفاصيل الأوردرات`. Code-only release: graceful FPM reload and Blade cache, no maintenance, DB mutation or worker restart. Recovery checkpoint: `/home/ubuntu/herokid-shipping-sku-before-20261029/`; rollback base: `b88ae971c262c04fa9742cfd9385283720aa2a7c`. Candidate/deployed read-only probes reconciled all six assignment groups; authenticated nora breakdown and disclosure checks passed. See [SKU deployment notes](shipping-sku-breakdown-2026-10-29.md).

The net-value/daily-employee enhancement was explicitly approved and deployed at **19:48:31–19:48:34 UTC on 29 October**. Its deployed SHA was `b88ae971c262c04fa9742cfd9385283720aa2a7c`, subsequently superseded by the SKU enhancement below. It changes only report service/view behavior (plus tests/notes), with no asset changes, maintenance, schema/data changes, integration actions or worker restart. Graceful FPM reload and Blade cache succeeded. The code recovery checkpoint is `/home/ubuntu/herokid-shipping-net-before-20261029/`; rollback base is `9efc343f07aa8aef7c121779ed9013528f73e693`. See [net KPI deployment notes](shipping-net-daily-kpi-2026-10-29.md).

The shipping release was explicitly approved and deployed on 29 October at **19:05:02–19:05:06 UTC**. It changed the shipping-report service, view, compiled CSS/manifest, tests and documentation only. No maintenance mode, schema/data migration, worker restart or stabilization release accompanied it. PHP-FPM received a graceful reload and Blade templates were cached.

At the last root inspection, production had a **preexisting mode-only** change to `bootstrap/cache/.gitignore` and three retained untracked CSS files: `app-Bhh21A7h.css`, `app-D3H9Up8z.css`, `app-Dzb4crYr.css`. These are observations to compare, **not a permanent allowlist for arbitrary drift**. The cache directory is restricted; `ubuntu` alone can produce incomplete Git status and permission warnings. Use `sudo git -C /var/www/herokid status --short` for the complete inspection. Do not recursively loosen permissions.

The primary development checkout can be on an unrelated branch with extensive uncommitted work. Never deploy its current contents merely because it is the task's working directory. The migration and shipping releases were transferred as verified local Git bundles; a successful AWS deployment is not proof that GitHub `main` contains that release.

## 2. Access and secrets

Use the existing key and sudo access. If unavailable, request only the missing authentication/authorization; never ask for a private key/password in chat. A key was exposed in an old screenshot: do not reuse or reconstruct it. The public-key field in a hosting UI accepts a public key, never a private key.

Do not dump `.env`, `bootstrap/cache/config.php`, `config:show`, `phpinfo`, all environment variables, decrypted settings, Supervisor environments, full request logs or callback payloads. Inspect only allowlisted non-secret values or return booleans. Private backup/checkpoint directories are 0700 and secret files 0600/0640 as appropriate. Never copy production secrets into source, test containers, a Git bundle, frontend assets or reports. Do not run `artisan key:generate` on production.

Production IAM role at the migration audit: `HeroKid-Prod-EC2-S3-Role`, IMDSv2 required. Its production S3 write access means a different DB or bucket on this same instance is **not** a strong test isolation boundary. No static `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY` should be added. Revalidate role/access through authorized tooling without retrieving or printing temporary credentials.

## 3. Filesystems and storage

Production bucket: **`herokid-prod-media-2026`**, private, versioned, default SSE-S3/AES256 at audit. Versioning is recovery assistance, not an immutable or independently isolated backup.

| Data | Laravel disk | Physical destination / important rule |
|---|---|---|
| Customer photos, order attachments, production PDFs, Admin Media Library | `s3_private` | Scoped from `s3`, prefix `private/` |
| Story Library PDFs/covers | `s3_private` | Existing logical paths, including `story-library/`, beneath `private/` |
| Remote Studio settings/assets | `studio_private` | Scoped directly from `s3`, exact prefix **`private/studio-private/`** |
| Financial attachments | `local` | `/var/www/herokid/storage/app/private/expenses/`; DB disk stays `local` |
| Public catalog/site uploads | `public` | `/var/www/herokid/storage/app/public`; `public/storage` points here |
| Temporary processing | `local` | `/var/www/herokid/storage/app/private` and application-managed temporary subdirectories |

Expected non-secret storage switches:

```dotenv
PRIVATE_MEDIA_DISK=s3_private
PUBLIC_MEDIA_DISK=public
PROCESSING_DISK=local
STORY_LIBRARY_DISK=s3_private
STUDIO_PRIVATE_DRIVER=scoped
EXPENSES_MEDIA_DISK=local
PUBLIC_IMAGES_QUEUE_CONNECTION=redis
```

A path passed to a scoped disk is relative to its scope. Do not prepend `private/` again, or place Studio underneath `private/private/studio-private`. Preserve disk/path references and checksum/size evidence; never rewrite references before verifying destination objects. Do not rerun migration conversion commands as a routine code deployment. There is no approved destructive S3 cleanup or version-pruning policy.

## 4. Services, queues, scheduler and HTTPS

| Component | Current configuration / location |
|---|---|
| Nginx production vhost | `/etc/nginx/sites-available/herokid-production`, enabled through `sites-enabled` |
| Other enabled Nginx vhost | `herokid`; retains staging/legacy handling; inspect before changes |
| FPM | `php8.3-fpm.service`, pool `/etc/php/8.3/fpm/pool.d/www.conf`, socket `/run/php/php8.3-fpm.sock` |
| Current pool limits | `ondemand`, **3** children, `pm.max_requests=300`, PHP memory limit 512 MB; a proposed fourth child is not live |
| Supervisor production definitions | `/etc/supervisor/conf.d/herokid-production.conf`; legacy definitions also exist |
| Default worker | `herokid-default:herokid-default_00`, Redis `default`, timeout 840 s, tries 3, backoff 30 s, max-time 3600 s, stopwait 960 s |
| Conversation worker | `herokid-conversations`, Redis **`robodesk-conversations`**, timeout 25 s, tries 1, max-time 3600 s, stopwait 960 s |
| Reservation retry | Redis/default database retry_after 960 s at configuration verification; timeout must remain shorter than reservation retry |
| Scheduler | `herokid-scheduler.timer` → `herokid-scheduler.service`, `www-data`, `/usr/bin/php artisan schedule:run`, once per minute |
| Access / error logs | `/var/log/nginx/herokid-production-access.log`, `/var/log/nginx/herokid-production-error.log` |
| Laravel logs | `/var/www/herokid/storage/logs/`; inspect bounded tails, summarize without customer payloads |
| Certificate | `/etc/letsencrypt/live/hero-kid.com/fullchain.pem`; matching private key stays protected on host |
| Renewal | `certbot.timer`, HTTP webroot `/var/www/letsencrypt`, existing Nginx reload hook |

Both required workers were running at verification. Supervisor autostart/autorestart are enabled for them. The legacy database/default workers and order-automation worker remain stopped; do not start all Supervisor programs. The conversation worker was corrected from `database` to `redis` after cutover: **do not reintroduce the old database command from the cutover report**.

Do not add a duplicate cron/scheduler. Do not call `schedule:run` as a harmless diagnostic: it executes real tasks. Use service results, timers, `schedule:list` and read-only queue observations. Future delayed conversation jobs are normal; distinguish them from overdue jobs. Never replay the 81 historical failed jobs merely to reduce a counter. Historical automation remains budget-paused and order automation disabled.

Certificate expiry was 27 January 2027 and renewal dry-run succeeded during cutover; recheck the current certificate and timer rather than treating that date as permanent. Apex A was `63.188.124.6`, www CNAME `hero-kid.com`, TTL 300, no apex AAAA at audit. Do not change DNS for an ordinary release.

## 5. Database identity, backups and recovery

Production is **`herokid`**, not `herokid_staging`. Other databases observed on 29 October:

- `herokid_staging`
- `herokid_backup_verify_20261029`
- `herokid_migration_tests`
- `herokid_schema_verify_20261029`
- `herokid_offinstance_verify_20261029`
- `herokid_health_restore_20261029`

Names alone are not authorization to overwrite or delete them. Verify target identity, app bindings, scoped privileges and retained recovery points before any restore. No automatic cleanup has been approved.

Existing backup chain:

- `herokid-db-backup.timer`: **02:30 UTC**, persistent, up to 120 seconds randomized delay.
- `herokid-db-backup.service`: `/usr/local/sbin/herokid-db-backup`, followed by `/usr/local/sbin/herokid-upload-latest-backup`; uploader helper `/usr/local/sbin/herokid-upload-backup`.
- Existing S3 prefix: `s3://herokid-prod-media-2026/private/database-backups/`.
- Inspect service result **and successful verified-upload evidence**, remote size/checksum metadata and local archive integrity. A local `.sql.gz` or successful dump alone does not prove an off-instance backup.
- Latest predeployment off-instance check (31 October): `herokid-20261031T112337Z.sql.gz`; gzip, local hash and S3 checksum/size verified. No fresh restore test was performed for this archive.
- Historical audit archive: `herokid-20261029T153905Z.sql.gz`, 37,929,188 bytes, SHA256 `ad40ab1c6e19613cd481e9ca7b528469a46884358ddd47908dff00810a926177`. A separate restore validated it; it predates later AWS orders. The next scheduled backup had not yet run at this handoff.
- Frozen source export: `/home/ubuntu/migration-20261029/hostinger-final.sql.gz`. Earlier AWS backup `/home/ubuntu/herokid-aws-before-update.sql.gz` and environment backup `/home/ubuntu/herokid-env-before-update.backup` are recovery artifacts, not deployment inputs.
- Migration EBS snapshots `snap-0070e8ca1fe19f7fa` and `snap-04d8f609109201b36` predate final live writes; do not label them current backups.
- Shipping-release code checkpoint: `/home/ubuntu/herokid-shipping-kpi-before-20261029/`; archive checksum verified before deployment. This is a **code** checkpoint, not a DB backup.

Daily off-instance DB backups imply roughly 24-hour RPO if successful. Local binlogs are not verified off-instance PITR. DB dumps do not protect new local public images, expense files or secret configuration. Retention and encrypted filesystem recovery improvements are proposed but unapplied. Do not introduce new recurring backup storage costs or delete old backups without authorization.

## 6. Standard AWS release procedure

These steps are for an agent executing an authorized release. Do the preparation before asking for approval when approval is still missing. Explicit approval such as “انشر علي aws” for the reviewed change is sufficient; do not ask again. Do not silently expand that scope to unrelated prepared branches.

### A. Establish release identity and scope

1. Inspect local worktree/index and remotes. Read live `sudo git ... rev-parse HEAD`, complete status and service state. Choose a clean isolated checkout based on the actual live release, or an explicitly reviewed successor. Preserve unrelated work.
2. Record full `base_sha` and approved full `target_sha`; inspect `git diff --name-status` and the complete diff. Verify ancestry. Do not assume `main` is newer or correct. Stop on unexplained source/config drift.
3. Run relevant isolated tests. Use PHP 8.3 syntax checks, MySQL tests for MySQL-sensitive changes, and `npm ci`/`npm run build` against the release lockfile when frontend assets change. Include `public/build` and manifest; validate every referenced asset. Preserve old hashed assets. Tests must not have production credentials/network destinations.
4. Identify changes to dependencies, migrations, environment, route cache, jobs and external integrations. Prepare separate exact actions for those; a view/report release needs none of them. Inspect migration bodies, not just filenames. Never run all pending migrations implicitly.

### B. Transfer and prepare before touching live files

A verified Git bundle is an established option when the approved release exists locally but not on the server's remote. Example local preparation in a **clean release checkout**, with the variables filled from verified state:

```bash
# Bash: set these to the verified full SHA and the reviewed local branch first.
: "${base_sha:?verified live SHA required}"
: "${target_sha:?approved full target SHA required}"
: "${release_branch:?reviewed local branch required}"
: "${handoff_dir:?private local handoff directory required}"
[[ "$base_sha" =~ ^[0-9a-f]{40}$ && "$target_sha" =~ ^[0-9a-f]{40}$ ]] || exit 1
[ "$(git rev-parse "$release_branch")" = "$target_sha" ] || exit 1
git merge-base --is-ancestor "$base_sha" "$target_sha" || exit 1
test -z "$(git status --porcelain)" || exit 1
mkdir -p "$handoff_dir"
chmod 700 "$handoff_dir"
git bundle create "$handoff_dir/release.bundle" "$base_sha..$release_branch"
git bundle verify "$handoff_dir/release.bundle"
```

Transfer through the existing SSH key to a private per-release directory on EC2. Do not include `.env`, vendor secrets, databases or customer media. Verify bundle prerequisites on EC2, fetch the exact advertised branch into a fresh release ref, and verify the fetched commit equals the approved `target_sha`. Use `--no-write-fetch-head` if appropriate. An authenticated upstream fetch of the approved commit is another option; verify exact identity either way.

Before applying:

- Recheck live HEAD equals `base_sha` and every dirty/untracked path is understood and preserved. Compare changed paths against the reviewed allowlist.
- Acquire a deployment lock for the **entire single remote transaction** (for example `flock` on `/run/lock/herokid-deploy.lock`). Separate SSH calls do not retain a shell lock. Check for any earlier release process/lock as well; the shipping release used `/run/lock/herokid-shipping-release.lock`.
- Create a unique private checkpoint (0700), record old SHA/status, archive affected existing files and changed service configs if applicable, and verify the archive checksum. Do not overwrite a previous checkpoint. Ensure enough disk space.
- For a DB/schema change, verify a fresh off-instance backup and recovery procedure first. A code-only report release does not require a DB import or restore.
- Syntax-check staged PHP/config files before switching. `php-fpm8.3 -t` checks FPM; `nginx -t` checks Nginx when relevant. Review Composer scripts before any production dependency operation.
- Prepare an error handler and health gates. Automatic return to the old code is only safe for a reviewed code-only/schema-compatible release. Do not automatically undo DB migrations or external side effects.

### C. Apply only the necessary operations

The successful shipping release used this narrow sequence after all guards and backups passed:

1. `git checkout --detach "$target_sha"` from `/var/www/herokid`, **without force/reset/clean**. Stop if Git reports conflicts; never discard drift to make it work.
2. `systemctl reload php8.3-fpm` for graceful web-process/opcache refresh.
3. `sudo -u www-data php artisan view:cache --no-interaction` for the changed Blade views.
4. Read-only report/render verification and public HTTP checks, then a fresh deployed-SHA/changed-file comparison.

No Nginx reload, Composer install, Node build, DB migration, `config:cache`, `route:cache`, shared-cache clear, storage relink, queue restart or maintenance mode was needed for that release. Do not turn this observation into a guarantee for other release types.

For config/routes/dependencies/job changes, add only justified, reviewed steps. Preserve APP_KEY and existing integration settings; validate route/config cache compatibility. Gracefully refresh workers only when their loaded code/config requires it, after checking in-flight jobs and timeout windows. Do not use `supervisorctl restart all`, restart MySQL/Redis, or stop a job midway merely for a frontend/report change. Prepare costly transfers/builds before any required maintenance window.

**Do not use the repository's existing `deploy.sh` as the default AWS workflow.** Its current behavior includes Composer install, optional on-host npm build, config/route/view/shared-cache clears, all pending migrations, storage symlink and recursive `chmod -R 775`/chown. Those broad operations can affect locks, private permissions, DB state and unrelated work. Do not change the script itself merely to make this documentation accurate; replace/review it only as a separately scoped task.

### D. Validate and report

- Verify actual remote full SHA and changed-file diff/hash against the approved artifact; do not infer success from upload or checkout output alone.
- Confirm Nginx, FPM, MySQL, Redis and Supervisor active; required workers running and exactly one scheduler healthy. A timer can be active while its last service run failed—inspect both.
- Probe external HTTPS and, when useful, origin with `curl --resolve hero-kid.com:443:127.0.0.1` from EC2. Retain normal TLS validation. Check `/`, `/up`, `/cart`, www redirect and changed static assets.
- For admin work, use the existing authenticated browser session to verify the actual feature. No credential bypass or synthetic production business mutation. Shipping verification covered employee click, pagination preserving filters, and date-range form submission.
- Read-only CLI diagnostics may use a MySQL read-only transaction with a **session-only** query timeout, in-memory cache/session settings and outbound HTTP blocked. Do not run a feature test suite against production. Rendered HTML/model fields must not be dumped into public logs.
- Inspect bounded post-deploy Nginx/Laravel logs, new failed-job counts and queues; distinguish historical errors, expected 404s and future delayed jobs. No raw customer payloads/tokens in reports.
- Save timestamped sanitized evidence, exact SHA, test scope, baseline comparison and checkpoint. State any known limitations. Do not claim full checkout/payment/shipping lifecycle validation from a GET request.

**Source-permission gate (31 October incident):** keep private checkpoints 0700 and secret artifacts 0600/0640 explicitly, but do not carry a global umask 077 into Git checkout of served source. Use normal source umask 022, validate planned source directory traversal and changed-file readability as www-data, and preserve secret/cache restrictions. Check paths before releasing the deployment lock; do not fix this by recursively loosening the application or home directory.

## 7. Rollback boundaries

**Code-only rollback:** after confirming old code is schema/config compatible and no new business-side effect needs reconciliation, restore the recorded base revision without force, restore only modified configs if any, rebuild relevant caches and gracefully reload affected processes. Preserve existing uploads, `.env`, untracked assets and all DB/queue state. Validate the same health gates. The last shipping release's base is `9aec03a64cac749abc9109b4e5b6c0466592bb41`, but future releases must record a fresh base instead of hardcoding it.

**Database or cross-host rollback:** stop and plan reconciliation. AWS has accepted real orders, payments, provider callbacks, jobs and media since migration. Fence writes, preserve a fresh AWS backup and external-event/job outcomes, reconcile new data into the recovery target, then reopen exactly one writer. Never restore a stale source dump or switch DNS alone. Do not use `migrate:rollback` blindly: down migrations can destroy business data.

## 8. Hostinger and staging are not alternative live writers

Hostinger SSH: `ssh -p 65002 u470070883@194.55.133.161`; application `/home/u470070883/domains/hero-kid.com/public_html`. Existing authorized migration key/session may be available; a `/tmp` key path from past work is not durable access documentation. Never reuse the exposed private key or ask the owner to paste a password into chat.

Hostinger was retained in maintenance with a persistent source-write fence, disabled application workers and removed hPanel cron. Its original wrapper was `/home/u470070883/run-herokid-queue.sh`, originally every minute. Do not restart it or deploy there. The transport-only authenticated Bosta callback relay and retained payload/receipt journal must remain until at least **30 October 2026 15:36 UTC**, plus explicit reconciliation of pending/late events and approved retirement. Elapsed time alone does not authorize retirement. Verify current fence/relay state before recovery operations.

`https://aws.hero-kid.com` was blocked with HTTP 403, staging FPM disabled and staging-user outbound access fenced before production IAM was restored. The staging database and private staging bucket `herokid-staging-media-645314607155` exist from preparation; **do not reactivate staging with production write-capable instance IAM**. Separate DB credentials/bucket names alone are insufficient. Prefer local synthetic containers with no external network; for MySQL, use an internal Docker network and no published DB port.

## 9. Prepared work and known limitations — not live fixes

**Deployed 31 October, broad activation OFF:** `codex/order-confirmation`, SHA `4b566cfb91d3da7b8795be0716163db19222ad41`. Both additive migrations applied. 198 tests / 1,412 assertions passed on each isolated SQLite/MySQL; PHP 8.3 lint/build passed. Owner explicitly approved one live pilot, HK10-614 (2682), zero-value and not for shipping/production. Its attempt stopped on RoboDesk close HTTP 500 before calling sendMsg; status unknown, no automatic replay. Global confirmation and legacy integration remain disabled. Actual sending and inbound confirmation are not verified. Await upstream close-error investigation. A deployment source-permission incident caused 26 HTTP 500s across 11:27:33–11:28:20 UTC; corrected with scoped permissions/cache refresh, no further 5xx through the observed post-recovery window. See [deployment, incident, pilot and recovery evidence](order-confirmation-deployment-2026-10-31.md).

The separately prepared branch **`codex/zero-cost-production-stabilization`** has code commit `3b4a8d38239f5a36a12a3a9f1de97a00d6e0091c` and documentation head `066d52a865f389cf5f78f9fbd76810ed5b106460`. Both it and the shipping branch were preserved as local refs in the primary repository. These refs are not a claim of GitHub publication. Do not accidentally deploy that entire branch over the newer shipping feature; review/cherry-pick/retest any intended combination.

Prepared, **not enabled/deployed** at handoff:

- `ops/health` local systemd monitoring package with thresholds, deduplication and cooldowns; existing Telegram adapter. No active new timer or outgoing alerts. Enabling the timer, outbound alerts and performance changes requires appropriate authorization. Do not invent a Telegram token/recipient.
- Conservative PHP-FPM change from 3 to 4 children and privacy-preserving Nginx timing logs. Eight saturation warnings were seen by the stabilization audit; sampled memory allowed a proposal, not proof of peak safety. No resize, fifth child or DB tuning applied.
- Media Library S3 delete fix using stream → verified recovery copy → delete/DB transaction with recovery, rather than Flysystem copy/move. The known production delete defect remains until that release is approved/deployed.
- Missing `/images/og-cover.jpg` fix using existing approved brand art, conversation cursor precision fix, and corrections for six historical full-suite failures. Full stabilization tests passed on its own branch; this is not a blanket pass for arbitrary combined releases.
- `/meta.json` requests observed with Facebook crawler user-agent; no app origin found. Leave the endpoint unchanged rather than inventing a file/redirect from a guess.
- Meta CAPI enabled/Pixel configured but token missing: exact key `META_CONVERSIONS_API_ACCESS_TOKEN`. Obtain a valid token for the existing dataset through a secure file/authorized admin configuration, plus configuration/worker-refresh authorization. Future-events-only recovery; do not replay 1,849 historical failed/configuration-missing events or the new affected events automatically. Worker DONE does not prove provider delivery.
- Historical missing share-card files remain accepted preexisting limitations with records intact. Do not delete records to conceal them.
- Audit found unencrypted EBS, broad SSH ingress, pending OS reboot and no independent off-host alerting. Do not change IAM/firewall, reboot, resize, add paid services or retire recovery resources without a new explicit scope/approval.
- Backup/local filesystem DR and retention gaps remain as described above. No new cleanup/retention policy has been enabled.

The zero-cost constraint means **$0 new fixed recurring resources/services**, not a guarantee that existing metered S3/transfer/CPU-credit usage never changes. Use existing local logs/tools and resources.

## 10. Shipping KPI rules and verified limitation

- Current shipping state must be shipped/delivered, including mapped custom behaviors. Return/other states are excluded. A checkout counts once after all active order parts qualify; delivery does not earn a second shipping credit. Merged source aliases are excluded.
- Credit belongs to the **current assignee**, even if changed after dispatch; this was explicitly selected by the owner.
- Filter by earliest documented Bosta dispatch event, otherwise local shipped-status transition, converted to Cairo day boundaries. Creation/delivery-only/updated timestamps are not substitutes.
- Employee filter scopes period cards and the zero-filled daily table. Daily rows split story/product/add-on quantities and net values; their sum matches the period total. All KPI amounts exclude shipping and deduct the recorded checkout discount once, allocated proportionally with exact-cent reconciliation. An optional day filters the SKU breakdown and order details; period cards/daily totals remain full-range. The separate comparison table explicitly covers all employees. Paid/remaining remain actual invoice balances including shipping, not period collections.
- Financial values require existing `orders.statistics.view` or `order_reports.view` in addition to `shipping_reports.view`; shipping-only access does not gain financial rights.
- **162 currently shipped/delivered checkouts lacked documented dispatch dates at deployment.** They are retained and warned about, excluded from date-range attribution. Do not fabricate dates for KPI settlement. Counts/assignees/balances are current-state, not an immutable payroll ledger.
- Latest SKU validation: 78 related tests / 504 assertions on each of SQLite and isolated MySQL passed; build and PHP 8.3 lint passed. Read-only candidate/deployed probes reconciled category/day/employee totals. Authenticated production browser verified employee and day filters. The pre-existing 162 undated checkouts remained unchanged.

## 11. Evidence and keeping this handoff current

Use live observations first, this dated runbook second, and older reports as historical evidence. In particular the cutover report's database conversation worker and the health audit's optional paid monitoring proposal were superseded by the Redis correction and zero-new-cost requirement.

Repository/source references include `config/filesystems.php`, `config/media.php`, `config/story_library.php`, `config/public_images.php`, `routes/console.php`, the shipping report service/view, and [shipping implementation notes](shipping-employee-kpi-2026-10-29.md) in the release branch. The primary workspace also contains dated reports named `aws-production-cutover-2026-10-29.md`, `aws-post-migration-health-audit-2026-10-29.md`, and `aws-zero-cost-stabilization-2026-10-29.md`; availability differs by branch. The canonical instructions above are self-contained and do not require those local-only files.

Historical private working directories include `/tmp/herokid-aws-migration-release`, `/tmp/herokid-health-audit-20261029`, `/tmp/herokid-stabilization-20261029`, `/tmp/herokid-zero-cost-stabilization`, `/tmp/herokid-shipping-employee-kpi`, and `/tmp/herokid-shipping-deploy-20261029`. They may disappear and are not a durable release mechanism. Never commit their raw inventories, database copies, callback payloads, secrets or private logs. Preserve reviewed source in Git and recovery artifacts in authorized private locations.

After a deployment, update the date, live full SHA, change scope, verification evidence, rollback checkpoint and prepared-vs-live statuses. Do not erase historical evidence or silently convert a proposed fix into a claimed completed fix.
