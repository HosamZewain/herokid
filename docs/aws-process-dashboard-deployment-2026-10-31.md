# Process builder and Month Over Month — AWS deployment

31 October 2026. Explicit owner instruction: deploy completed work. Production code is **fd04fdce4f877b419b291041a66fbb8c6b7d299e**, detached at `/var/www/herokid`. Code deployment ran **14:51:23–14:51:34 UTC / 16:51 Cairo**, with no maintenance mode. GitHub branch: `codex/automation-process-builder` in `HosamZewain/herokid`. A later documentation-only commit on that branch records this release; it is not the live application SHA.

## Live scope

- Named process designer, conditions, steps, branches, versioning, synthetic simulator and execution-history interfaces.
- Guarded execution and canonical identity generation/private preview/approval adapters are installed, but **process execution, identity AI and identity-template readiness remain OFF**; no new process workers were installed or started. Confirmation owner remains `legacy`, enabled only for new orders after ID2683. No historical replay, paid trial or synthetic production order was run.
- RoboDesk template delivery receipts are visible after the next ordinary full poll. Unknown remains unknown, failed requires alternate-channel staff follow-up, and nothing resends automatically. Completed historical confirmations are not backfilled by this feature.
- One six-month dashboard line chart: checkout count, story pieces, product/add-on pieces, average net item value excluding shipping, and documented dispatch-month shipment count. Current month is partial. Existing financial permissions and dashboard section order are preserved.
- Meta template `herokid_identity_review_payment` (1394505159141810) was checked in the live console after deployment: **active, quality pending**, Utility, Arabic. Catalogue synchronization in RoboDesk and a specifically approved bounded identity trial remain unverified; the live template flag stays false. Meta approval alone does not prove the end-to-end identity workflow.

## Safety and deployment

Fresh existing backup job completed at14:39:16 UTC: `/var/backups/herokid/herokid-20261031T143851Z.sql.gz`, gzip/local checksum and existing S3 object size/checksum metadata verified before mutation. No fresh restore drill was performed. Private code/config checkpoint: `/home/ubuntu/herokid-process-dashboard-before-fd04fdc`; rollback code base `adfaffa9841c79a16dd3d37383e2f3209a6736f1`.

Only three reviewed migrations were applied: `2026_10_31_160000_create_order_process_simulations`, `2026_10_31_170000_create_order_process_runtime`, `2026_10_31_180000_create_order_process_identities`. All other migration paths were excluded. Existing APP_KEY, protected environment and integration values were preserved. The cached-config comparison allowed only the new disabled process configuration and its two queue connections. No IAM, DNS, firewall, sizing or new AWS service changes.

Narrow non-forced checkout; 84 changed-file hashes verified; PHP8.3 lint; config/routes/Blade refreshed; FPM graceful reload. Existing default and conversation workers received graceful queue restart and were observed on new PIDs503493/503488. Paused legacy workers remain stopped. The deployment script's final broad `supervisorctl status` returned exit3 because those programs are intentionally STOPPED, after the completion checkpoint had been written. This was checked explicitly: both required workers RUNNING and all five services active; no rollback or paused-worker activation was needed.

## Validation

- 375 selected tests / 2,803 assertions passed on each isolated SQLite and MySQL (261 process/integration/canonical identity;51 dashboard/shipping;63 checkout/web/mobile/admin). Follow-up private-preview tests26/195 passed on both. Seven frontend tests passed. These are targeted suites, not a claim that every repository test ran. Synthetic fixtures and external-request blocking throughout.
- Build/manifest, retained historical assets and PHP8.3 syntax passed. Runtime test container used PHP8.5; production compatibility syntax was checked on8.3.
- Authenticated production dashboard verified all five lines, month selection, hide/show and six-month table. No browser-console errors. Chart query measured789–894ms in two read-only CLI samples; not a load benchmark.
- October snapshot after a naturally arriving order:581 purchases,264 story pieces,604 product pieces,335 dispatched purchases, average495.35 EGP without shipping. 162 historical shipped/delivered purchases lack documented dispatch dates and remain explicitly excluded from month allocation. No dates were invented.
- New automation landing page and existing confirmation delivery column verified authenticated. Generic process runs and identity tasks stayed0; both new queues empty. Default queue empty; conversation queue sample2 waiting/1 reserved/19 delayed while its worker ran. No stalled-backlog claim from this single sample.
- Production order rows increased naturally2686→2687 during observation; no production order was created by the agent. Failed-job baseline stayed81. Scheduler exit0/success at14:54:03 UTC.
- Apex health/home/cart, www redirect and new chart asset passed HTTPS. Bounded postdeploy observation:221 HTTP requests,0 HTTP5xx,0 new Laravel errors/bytes. This is immediate evidence, not long-term monitoring.

Evidence: local `/tmp/herokid-month-comparison-evidence/production-final.txt`, `production-chart.png`; `/tmp/herokid-process-identity-evidence/production-builder.png`; remote private checkpoint with before/after probes, schema logs and file hashes.

## Recovery and remaining activation work

Preserve all new tables, orders, payments, receipts and identities. Before any rollback inspect current gates, runs, tasks, queues and in-flight work; do not restore the predeployment database or run migration down. A code rollback requires the exact base, old compatible configuration/routes, preserved environment, graceful worker handling and matching asset/view/FPM refresh. If future identity execution has begun, reconcile canonical attempts, media and external effects before reverting; never blindly revert ownership or resend.

For identity activation: verify RoboDesk catalogue and eight-variable/button contract, agree a bounded trial/budget and customer/owner authorization, verify original photo authorization and provider pricing, configure scoped workers with documented timeout ordering, and set a future-only enrollment boundary. Payment evidence remains a human review; identity confirmation cannot mark funds paid. New recurring AWS resources:0. Future AI/WhatsApp usage is billable and remains separately gated.
