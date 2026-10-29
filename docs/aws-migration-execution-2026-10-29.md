# AWS migration execution readiness — 29 October 2026

Preparation and isolated staging validation are complete. **Recommendation: GO for a controlled, approval-gated cutover**, subject to the stop conditions below. This is a readiness recommendation, not authorization to execute. Hostinger remains the live system of record. No write freeze, production database replacement, production integration activation, or traffic cutover has occurred.

The user separately authorized temporary DNS TXT records for certificate issuance. Both challenge records were added, validated, and removed. Production ALIAS/CNAME routing stayed on Hostinger.

## Deployment and recovery checkpoints

| Item | Verified state |
|---|---|
| AWS original application | `/var/www/herokid`, detached `3b419730234c925792914713349d5942f77da01f`; original database retained |
| Isolated release | Branch `codex/aws-migration-execution`, based on that same SHA; deployed staging SHA recorded in `/var/www/herokid-staging/DEPLOYED_SHA` and the final handoff |
| Hostinger / upstream GitHub main | `b8c84ccc593c1a68f6d88e35f96f8d1613a8c013`, rechecked on October 29 |
| Local primary checkout | Unrelated dirty `redesign/playful-coherent` checkout was preserved; implementation used an isolated clone |
| Original AWS database | `herokid`: 1,555 orders, 129 migration records; not replaced |
| Staging database | `herokid_staging`: 1,555 orders, 151 migration records after all 22 pending migrations |
| Latest production inspection | 2,610 orders, highest order ID 2,612, 151 migrations; counts remain live and must be refreshed |
| EC2 / Elastic IP | `i-00fea72c8abdc72f3`, `63.188.124.6`, eu-central-1; EIP association verified |
| Recovery snapshot | `snap-04d8f609109201b36`, completed; contains the EC2 staging-preparation checkpoint, not a frozen final Hostinger export |

Existing `herokid-aws-before-update.sql.gz` passed gzip validation and was restored into the separate `herokid_backup_verify_20261029` database: 1,555 orders / 129 migrations. Its SHA256 is `23a4f6f5ba4c61f7d02f1ee9bc1a52e818558d1d27e181d4e0dd39d50bbd72f0`.

The protected pre-migration staging dump has SHA256 `7c03686906544cc22b64743a17b19fba108a9bd10855c670856bc421e799d8f1`. Order, expense-ledger and payment-event table hashes were unchanged by the staging migrations. Existing environment backup and Git stash `aws-before-final-migration` were retained.

A new live, single-transaction Hostinger preparation backup was exported without writing files on Hostinger. It is protected at `/home/ubuntu/migration-20261029/hostinger-production-preparation.sql.gz`; gzip integrity and transfer SHA256 passed: `b726468ad9c339bc76142f43eaf7597d4dccaa2272c62e520832020068d83912`. It is **not** the final frozen backup and was not imported into staging or production.

Hostinger runs MariaDB 11.8.9, with 157 InnoDB tables and no routines, triggers or events at inspection. A schema-only export, with no customer rows, imported successfully into `herokid_schema_verify_20261029` on AWS MySQL 8.0.46: 157 tables / zero orders. `normalize-mariadb-dump.py` removes only the MariaDB client sandbox directive for MySQL CLI compatibility and preserves all other bytes.

## Staging isolation and services

- Separate `/var/www/herokid-staging`, `hkstage` OS user, FPM service/socket, staging database account, Redis DBs 2/3 and staging key prefix. Staging database credentials cannot access `herokid`.
- A fresh staging APP_KEY and synthetic/local-only settings were used. Real external credentials were not copied into the staging environment.
- Separate `herokid-staging-media-645314607155` bucket: all public access blocked, bucket-owner-enforced ownership, AES256 encryption, versioning, TLS-required policy.
- Approved dedicated `HeroKid-Staging-EC2-S3-Role` replaced the EC2 production profile. It permits only `private/*` in the staging bucket and explicitly denies other S3 resources. Actual production ListBucket was denied; IAM simulation returned explicitDeny for production GetObject, PutObject and DeleteObject. No static AWS credentials were introduced.
- `hkstage` egress permits loopback, IMDS and eu-central-1 S3 HTTPS ranges only. External HTTPS was tested and blocked. IPv6 outbound access is blocked for that user. Mail is array-backed, integrations/AI automation are disabled, and no general staging scheduler or customer queue worker runs.
- Both `aws.hero-kid.com` and production-name validation vhosts restrict application access to loopback. External staging requests return 403. Original FPM is disabled; original Supervisor workers are stopped with autostart=false.
- Original AWS `.env` was found world-readable and tightened to 0640. Original cached configuration and private storage were restricted; `hkstage` read/traverse denial was verified. Staging `.env` is 0640 root:hkstage; protected backups/candidate production config are root-only.
- Redis now uses AOF with every-second fsync. Rewrite completed successfully; AOF write status is OK. MySQL and Redis bind to loopback. No Redis database was flushed.

Expected storage settings are verified: private/order/admin media and Story Library use `s3_private`; Studio uses the scoped prefix `private/studio-private/`; public catalog media stays on `public`; expenses and processing stay `local`; public-image jobs use Redis. A synthetic image job was processed from an isolated validation queue only.

## Validation results

| Validation | Outcome |
|---|---|
| 22 staging migrations | Passed; no order/expense/payment table-content changes |
| Focused storage / Studio / library / expense suite | 106 tests, 831 assertions, passed in isolated local runtime |
| Actual AWS IAM + real staging S3 tests | 8 tests, 146 assertions, passed on EC2 PHP 8.3.6; SQLite memory DB and UUID-scoped synthetic objects only |
| New child-attempt media registry regression | Passed; dry run, missing-object refusal, idempotent conversion, original preservation |
| Full suite, isolated PHP 8.5 runtime | 1,692 tests: 1,685 passed, 6 failed, 1 skipped; 15,909 assertions |
| Six failing cases on isolated AWS MySQL | 3 passed, 3 failed; 6 tests / 58 assertions, no errors |
| Diagnostic real-token / correct-URL rerun + new registry test | 3 tests / 45 assertions passed on AWS; diagnostic test edits were not application fixes |
| Native SQL restore | Original AWS backup restore passed; current Hostinger schema-only compatibility passed |
| Frontend | Fresh `npm ci` and clean `npm run build` passed; clean manifest reproduced tracked build SHA256 `7a065a3d0fabd43ac7f63c38a5804dbd714254e55d327fd734dd2af59a046f3f` |
| Web and API PDF delivery through Nginx HTTPS | Full PDF 200 / 1,016 bytes; Range 206 / 100 bytes, `Content-Range: bytes 0-99/1016`; full SHA256 matched synthetic S3 object |
| Protected media | Studio unauthenticated download rejected; real Studio login/token download and checksum enforcement passed; anonymous expense endpoint redirects to login; direct private storage inaccessible |
| Processing cleanup | No temporary `media-responses` files remained |
| Delta transfer | Real synthetic private + Studio upload/read path tested in staging; source preserved; metadata/size checks passed |
| Production local-media rsync | Dry run: zero file transfers for 356 public files and 91 expense files; source cache prepared securely |
| Nginx / scheduling / script validation | Production Nginx template syntax passed with actual certificate; scheduler unit/timer verification and shell/Python checks passed; all prepared production Supervisor programs have autostart=false |

Six-suite-failure analysis:

1. Two expense audit-value assertions fail on SQLite float/string representation; both pass unchanged on MySQL. Financial values and local attachments are preserved.
2. Child-image URL assertion expects `http://localhost`, while the test environment configured `https://hero-kid.com`. It passes with the expected APP_URL; signed production URL generation is not a migration defect.
3. Owner-dashboard assertion expects an absent Arabic statistic label. The preceding permission-denial checks pass. This is existing dashboard/test expectation drift, not a demonstrated permission bypass or storage regression.
4. Mobile/agent workflow uses a mocked Sanctum token whose `abilities` property is false. The diagnostic using a real database-backed token passes; real Studio token authorization also passes against S3.
5. WhatsApp saved-history pagination passes unchanged on MySQL; the original SQLite failure is environment-dependent.

The skipped full-suite test requires native MySQL dump/restore clients. Its absence was supplemented by the separate verified SQL restore and current schema-only import. The full suite is **not reported green**. The existing Admin Media Library S3 delete defect remains explicitly deferred as requested.

Build warnings: stale Browserslist metadata and a large HEIC chunk remain. Required Cairo fonts, absent from tracked source, were read from Hostinger and copied to staging; final deployment must preserve `public/fonts` and other existing untracked public assets. No secrets or customer media were committed.

## Production media reconciliation

Production S3 has 22,218 objects / 36,298,437,138 bytes under `private/`; public access blocking, encryption and versioning were verified independently through the authorized console.

The 12:32 UTC reference snapshot contained 23,744 references (including 182 public references). For private/Studio references:

- 20,742 had source files and matching S3 sizes; no size mismatches were found.
- 124 references pointed to 105 unique source files not yet in S3: 245,042,467 bytes. These are live pre-sync deltas, not data loss.
- 2,696 references lacked source files; 38 still have S3 copies. The remaining 2,658 are an existing missing-media baseline: 2,653 temporary-upload references, two explicitly deleted child-profile photos, and three old share-card references on succeeded child-generation attempt 12 (July 23).
- Those three old share-card files are absent from both exact source paths and S3. Their records were preserved. No other child's image or unrelated matching basename was substituted.

The complete filesystem inventory includes 18,897 private files, three Studio files and 356 public files. Its timestamp/size delta plan requires **209 S3 transfers / 278,964,432 bytes** at that snapshot, including files not directly referenced by current rows. This is about 279 MB; it will change while Hostinger remains live.

Public storage is 198,535,841 bytes; local expenses are 65,597,146 bytes / 91 files. EC2 matches source presence and sizes, and the real rsync dry run schedules zero file transfers. This does not claim that every preexisting object's contents were re-hashed; synthetic fixtures and newly transferred files were checksum-validated.

Coverage audit includes explicit disk columns, implicit order photo arrays, production scenes/layouts/assets/proofs, Story Library PDFs/covers, child identity shares and attempts, public catalog JSON galleries, admin notes/media and separate Studio roots. A real defect was fixed: the registry used singular `child_identity_attempt_photo` instead of actual `child_identity_attempt_photos`, skipping 235 production references. Expenses are deliberately excluded from S3 conversion. Public variant records stay public; conversation reply media has no active attachments in the inspected production DB; three database-export records were expired and remain local.

`check-media-baseline.py` currently correctly fails on the 124 unsynced references. At cutover it must report zero new/unsynced missing references and zero size mismatches. It may report only the exact unchanged historical baseline above. The application's strict verifier will still return nonzero for historical missing paths; that must not be hidden or treated as permission to delete rows. Conversion skips records whose required target objects do not exist.

## Production configuration and infrastructure

A root-only inactive `/home/ubuntu/migration-20261029/production.env.prepared` preserves Hostinger's APP_KEY/integration configuration, uses existing AWS DB credentials, and applies the reviewed hybrid-storage/Redis overlay. It was not activated. Refresh it after the final source configuration check; preserve the production APP_KEY to decrypt existing provider/notification credentials and validate signed links.

Bosta callback remains `https://hero-kid.com/api/integrations/bosta/webhook`; Studio/agent/mobile routes and RoboDesk feed URLs retain the same production hostname. No real Bosta, payment, push, WhatsApp, mail or billable AI call was executed from staging. Provider allowlists and real side effects must be checked during the approved production smoke phase. Do not invoke `schedule:run` merely to inspect it: it can clean files, queue messaging and operate external integrations.

Hostinger has one hPanel cron every minute calling `/bin/bash /home/u470070883/run-herokid-queue.sh`. That wrapper runs the scheduler, a database `robodesk-conversations` worker and a separate default database worker with locks. At inspection there were 10 unreserved conversation jobs, zero reserved jobs and 81 failed jobs. No failed jobs were retried. Production automation had one `paused_budget` run; order automation had no runs. Recheck all counts at freeze.

Original Redis retry_after was already 900 seconds with 840-second workers; this was not an existing mismatch. Prepared production values use 960 seconds retry/stop wait for general workers, 25-second conversation jobs, and the dedicated order-automation connection's 600-second reservation window with 240-second worker timeout. Preserve queued database jobs when switching the default connection to Redis; the prepared drain worker explicitly uses `database`.

The t3.medium has about 3.7 GiB RAM plus 2 GiB swap; final audit had about 2.5 GiB available, no swap usage, and 19 GiB disk free. MySQL's current buffer pool is 128 MiB. Start conservatively with one default worker and observe PDF/image memory peaks before starting the second. The 31+ GiB source private tree cannot be staged in full on the 30 GiB root volume; the transfer script bounds staging to one file at a time and checks free disk.

Security group exposes only 22/80/443, but SSH is open to 0.0.0.0/0. SSH passwords/root login are disabled. Restrict SSH to a verified stable admin range when available; no unverified network lockout change was made. Standard EC2 metrics showed zero status-check failures, CPU max about 24.6% and credits near 576 in the reviewed six-hour window. Detailed monitoring and CloudWatch alarms are not enabled; disk/memory alerting is still an operational improvement, not falsely reported as configured. During cutover, actively monitor errors, queues, disk and memory.

Daily 02:30 UTC local database backups are installed, enabled and tested (`herokid-db-backup.timer`). They create gzip files plus SHA256 under root-only `/var/backups/herokid`; no automatic pruning occurs yet. `upload-backup.sh` prepares verified off-instance production backup upload using the production IAM role; it cannot run under the isolated staging role. Enable/test that upload after the approved profile transition, before accepting production writes. The completed EBS snapshot provides the current off-instance EC2 checkpoint; recurring off-instance DB backup and retention are not yet active.

## HTTPS and DNS

A new Let's Encrypt certificate for apex and www is installed on EC2, valid until **27 January 2027**. Both names passed TLS verification and returned HTTP 200 via loopback `--resolve`; external access to the validation vhost remains denied. The full production Nginx configuration is prepared and syntax-validated but not activated.

Live authoritative nameservers are `orbit.dns-parking.com` / `horizon.dns-parking.com`. The apex is an ALIAS to `hero-kid.com.cdn.hstgr.net`, and www is a CNAME to `www.hero-kid.com.cdn.hstgr.net`, both TTL 300. Public answers include Hostinger IPv4 and IPv6 addresses. Cutover must handle both names and stale IPv6/CDN routing, not merely add an apex A record beside the existing ALIAS.

The manually issued certificate is not yet automatically renewable. After approved traffic cutover, `renew-production-certificate-after-cutover.sh` switches to webroot validation, runs renewal dry-run and installs an Nginx reload hook. Port 80 challenge handling is in the prepared production vhost. Do not claim renewal validated until that post-cutover test passes.

## Final cutover execution plan — DO NOT START WITHOUT EXPLICIT GO-LIVE

1. Record the user's GO-LIVE approval and accepted historical missing-media baseline. Recheck upstream/Hostinger SHA, order counts, free space, active processes, IAM profile, final release SHA and rollback ownership. Stop if source code changed; inspect and retest before deployment.
2. Take fresh Hostinger database/config backups plus AWS pre-import DB/config backups and a fresh EC2 checkpoint. Validate gzip, checksums, target identities and recovery locations. Retain original dumps unchanged. The preparation export is not a substitute for the frozen final export.
3. Safely stop new Hostinger writes, including storefront/admin/mobile/Studio/agent writes and provider callbacks. Pause the hPanel wrapper first so no scheduler/worker restarts. Let in-flight workers finish bounded jobs; inspect process list, reserved jobs, database transactions and external jobs. Do not kill active non-idempotent payment/shipping/message operations blindly. Configure maintenance/webhook retry behavior; confirm providers retry or durably hold callbacks.
4. Capture pending/reserved/failed-job counts and payload identifiers privately. Preserve database job rows in the final dump. Drain safely where appropriate; otherwise transfer pending jobs once and wait out stale reservations only after proving the old worker is stopped. Keep the 81 existing failures separate; never mass-retry them. Check remote AI operations and paused automation leases before changing ownership.
5. Capture a fresh frozen filesystem inventory. Refresh S3 object inventory under the production role only after shutting down staging FPM/tests and blocking its access to IMDS. Restore the known production instance profile only in this approved phase. Verify account/role/bucket identity. Never leave staging processes running with production S3 write access.
6. Execute `delta-sync.py --apply --go-live-approved` from the trusted SSH controller, using the frozen inventory. It rsyncs bounded files from Hostinger, verifies size/mtime, streams to S3 through EC2 IAM, verifies object metadata/length and journals version IDs/checksums. It never deletes Hostinger or S3 originals. Include the separate Studio prefix. Refresh inventory and rerun until the delta is empty; no customer record conversions yet.
7. Refresh the protected local cache and execute `local-media-sync.sh --apply-after-go-live` for public and expenses only. Preserve untracked public assets/fonts/build artifacts. Set correct production ownership. Re-run rsync/checksums and compare expense rows and file references; never convert expense disks to S3.
8. Export the **frozen** Hostinger MySQL database with the reviewed script, capture exit status using pipefail, validate gzip/SHA256, transfer securely and retain both original and normalized forms. Verify source/final counts and no active writers. Do not log SQL data or credentials.
9. With the AWS production application/workers still stopped, verify target database is `herokid`, preserve its recovery dump, then replace/import only after the approved target check. Never target `herokid_staging`. The dump intentionally has no DROP TABLE statements; import into the deliberately prepared empty production target. Stop on the first SQL error, never continue with a partial import.
10. Deploy the pinned validated release to `/var/www/herokid`, preserve `.env`/storage/untracked assets, install locked Composer dependencies if needed, and activate the prepared production environment only after secure final refresh. Run `migrate:status` first; current Hostinger already has 151 migrations, so do not assume the old staging backlog still applies. Run only pending migrations against the verified production DB; no rollback by `migrate:rollback` for irreversible data migrations.
11. Reconcile final DB references against refreshed S3 inventory. Require zero new missing/size mismatches beyond the unchanged recorded historical baseline. Run the strict verifier to a protected report, inspect it, then dry-run `media:migrate-disk-references --target-private=s3_private --target-public=public`. Apply only after target objects are verified. Re-run for idempotence. Keep expense disks local and confirm expense/payment/order counts and financial hashes remain unchanged apart from intentional reference columns.
12. Verify Studio settings revisions/checksums, public assets, protected order downloads, library PDF full/Range requests and local receipts via production-hostname `--resolve`. Rebuild config/route/view caches, verify writable paths and production APP_KEY continuity. Enable tested off-instance DB backup upload and confirm a retrievable archive before opening writes.
13. Install production Nginx/FPM and verify apex/www TLS using the actual production certificate. Keep production access restricted until the smoke gates pass. Start only the needed workers in the approved phase, including the imported database-job drain and database conversation worker. Enable one production scheduler after confirming Hostinger's scheduler is stopped. Initially leave risky automation paused until provider state is reconciled.
14. Change the apex ALIAS to the approved A/ALIAS target `63.188.124.6` and update www to the AWS/apex target. Remove conflicting old IPv6 routing only as part of this GO-LIVE change. Preserve mail/DKIM/SPF/DMARC and unrelated records. Verify authoritative and public recursive A/AAAA/CNAME answers, CDN behavior and both names. Keep Hostinger write-blocked through DNS convergence; never allow simultaneous independent writers.
15. Run controlled production smoke tests: login/admin authorization, a traceable approved checkout, uploads, order/job creation, Studio protected downloads, PDF ranges, expense retrieval, and necessary provider callbacks. Observe live orders, 5xx, queue age/failures, retries, disk/memory and DB connections. Live paid/shipping/message operations require the approved smoke scope; do not manufacture customer actions. Complete webroot renewal reconfiguration and its dry-run.
16. Monitor continuously during the initial acceptance window and retain Hostinger/backups. Record the first AWS write time and all new AWS orders/financial events/media/job side effects. Do not decommission Hostinger or delete source media after a DNS switch.

## Estimated cutover window

Reserve an initial **30–60 minute write-freeze window**. This is a planning estimate, not a measured full-cutover SLA. Main drivers are the refreshed media delta (currently about 279 MB), controller/SSH throughput, final dump transfer/import, per-object reference checks/conversion, draining in-flight jobs and smoke validation. TTL is 300 seconds, but resolver/CDN/IPv6 convergence can outlast it; keep Hostinger write-blocked until routing and callbacks are settled. If the delta or active-job backlog grows substantially, recalculate before starting the freeze.

## Rollback and stop conditions

Before AWS accepts writes: stop AWS services, restore the saved DNS records and return traffic to the unchanged Hostinger code/database. Verify authoritative routing before removing Hostinger maintenance. Preserve AWS failure logs and snapshots.

After AWS accepts writes: freeze AWS first; export its current DB, pending jobs and new/versioned media. Reconcile all new orders, payment/shipping/message outcomes, identity changes and file references back to Hostinger before reopening it. Importing the stale pre-cutover dump or merely flipping DNS would lose writes or duplicate side effects. Keep both sites frozen if reconciliation is uncertain. Use full DB recovery for irreversible migrations; retain production S3 object versions.

Stop the cutover on any new code drift, unverified backup, active source writer, unknown reserved/non-idempotent operation, wrong IAM/DB identity, failed SQL import, newly missing media, expense-reference conversion, HTTPS failure, or sustained smoke-test failures. The known historical missing paths and six diagnosed suite failures must remain explicit in the acceptance record. Do not weaken isolation to make a test pass.

The accidentally exposed old SSH private key still needs rotation wherever it was authorized. This execution uses the newly added dedicated migration key; unrelated existing key authorizations were not revoked blindly.

## Evidence and resumability

Source/config/backup evidence is protected under `/home/ubuntu/migration-20261029`; prepared production files are in `/home/ubuntu/migration-ops-prepared`. Synthetic test source/logs are in `/var/www/herokid-migration-tests` and `/home/ubuntu/*-test*.log` / `*-regressions.*`. Local inventories, SQL files and detailed test logs are in `/tmp/herokid-aws-migration-20261029`, with private files excluded from Git. The local media cache is `/tmp/herokid-migration-local-media`. Temporary paths must be preserved or regenerated before resuming on another machine.

Operational scripts are in `ops/aws-migration/`. None of the production templates automatically authorizes go-live. The staging FPM/firewall remains active and original production workers remain disabled at handoff.

References used for implementation details: [AWS IP ranges](https://docs.aws.amazon.com/vpc/latest/userguide/aws-ip-ranges.html), [Laravel queue timeouts](https://laravel.com/framework/docs/12.x/queues#job-expirations-and-timeouts).
