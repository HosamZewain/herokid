# Process builder — first implementation increment

**Deployment update,31 October14:54 UTC:** this code is now installed on AWS in `fd04fdc`. The designer/simulator is available; generic execution and identity AI remain disabled. Existing legacy confirmation remains active. Meta identity template is active/quality pending; RoboDesk catalogue and approved trial remain outstanding. [Verified deployment and recovery](aws-process-dashboard-deployment-2026-10-31.md). The preparation narrative below records the earlier implementation stage.


Date: 31 October 2026. Branch: `codex/automation-process-builder`.
Status: **prepared and tested locally; not deployed or activated**.

Continuation: the [execution increment](automation-process-runtime-2026-10-31.md) now implements the event outbox, guarded runtime, response waits and separate live-history controls. The original increment notes below are retained as historical scope; the continuation supersedes their execution-engine TODOs.

## Delivered

- `/admin/order-automation` now presents a workflow list and new-workflow creation as the main interface. Existing confirmation operation has its own card/details; legacy identity settings remain available.
- Schema 2 definitions support named drafts, checkout/story/product scope, new-checkout/status-entry triggers, status filters, all/selected stories and products, ordered steps and forward branches.
- Six step types: template message with ordered field mappings, correlated-response wait, guarded status change, condition branch, staff review, end. Custom template names are design inputs, not a claim that those templates exist or are approved.
- Confirmation is supplied as an editable **simulation preset**. Its reject/timeout branches request staff review. It does not replace, enroll, modify or send through the running confirmation service.
- Graph validation rejects broken links, backward edges/cycles, unreachable steps, duplicate identifiers/replies, unknown action/config fields, unsupported statuses and waits whose referenced message is not present on every incoming path.
- Saved revisions remain immutable. Optimistic draft versions prevent lost edits. Running simulations retain their original revision.
- Persisted simulations use explicit synthetic fixtures only; they do not accept an order identifier or load customer orders. They record a deterministic trace, wait across requests, support confirm/reject/manual-review/timeout decisions and prevent repeated response consumption with a locked version.
- Statistics and history are labelled as simulations, separate from existing operational counts. Completion means simulated completion, not message delivery or production success.
- Existing view/manage permissions protect the editor and simulations. No permission grants or paid activation are added.
- Legacy activation, start, scanner and runner reject schema 2, including if an enabled flag is changed outside the UI. Existing schema 1 behavior remains intact.

## Explicit remaining work

This increment is **the builder and durable simulator**, not completion of the generic live workflow engine or all of design phase 1.

Not yet implemented: transactional business-event capture, general live enrollment, production node executors, provider receipt reconciliation for arbitrary workflows, dynamic response subscriptions, per-item live child executions/joins, handoff across workflows, migration of current confirmation ownership, generic workflow activation/pause, AI identity/save nodes, payment evidence ingestion or ledger changes.

The synthetic simulator models the selected cohort and its decisions; it is not proof of item-level production execution. Product selection is by product ID, not variant/SKU. AI generation and payment actions are deliberately absent from the action palette until their adapters and business invariants are implemented. No generic raw HTTP/code action exists.

Next engineering slice: durable event/run/node execution records and provider adapters, reusing the validated definitions while keeping simulator effects separate. Audit every status mutation path; enforce one confirmation owner and preserved receipts/boundary during a separately reviewed handoff. Use the existing payment ledger with verified amounts and separate production-release decisions; do not call the old full-payment/ready-for-print proof approval blindly.

## Verification

- 108 relevant PHP tests / 443 assertions passed on isolated SQLite, and the same 108 / 443 on isolated MySQL. Includes new process-builder tests, legacy identity workflow, actual confirmation adapter tests using fake HTTP, RoboDesk integration and conversation refresh regressions.
- Test runtime: PHP 8.5. PHP 8.3 syntax checks passed for changed PHP files; no claim of a full PHP 8.3 test-suite run.
- 4 frontend browser/module tests passed, including adding connected nodes, variable/branch editing, preserving identities during reordering, serialization and escaping hostile labels.
- Vite production build passed. Previously committed hashed assets retained; manifest references checked. Existing large-chunk warning remains; no new paid dependencies/resources.
- Authenticated pages rendered from synthetic SQLite data. Headless local browser checked the workflow list, editor and waiting simulation at 1440px, plus editor at 390px. No horizontal page overflow or JavaScript errors. External requests were blocked; logo/media absent from the isolated fixture are not evidence of a production issue.
- MySQL verification found and resolved the catalog column naming mismatch and JSON-object key ordering in simulation request deduplication. Tests cover product names/filters and repeated requests on both engines.
- Evidence: `/tmp/herokid-process-evidence/` (`sqlite-final.log`, `mysql-final.log`, `frontend-final.log`, `php83-lint.log`, build/visual logs and synthetic screenshots). These are local evidence artifacts, not production logs.

## Deployment boundary and candidate procedure

Owner requested implementation, not deployment. Live SHA was read over SSH as `adfaffa9841c79a16dd3d37383e2f3209a6736f1` at the start of this work. No production changes, worker refresh, real messages, paid AI calls, configuration changes or data cleanup were performed.

A future approved deployment needs the standard runbook gates plus the additive migration `2026_10_31_160000_create_order_process_simulations.php` before exposing the new index query. Migration creates only the simulation table; it does not seed enrollments or alter orders. Preserve existing definitions/revisions and all confirmation receipts. There is no generic live activation switch in this increment.

Bundle contains Blade/routes/assets and safety checks in legacy workflow services. Review actual running worker use before deciding on a graceful scoped worker refresh; do not blindly restart workers. Regenerate approved route/Blade caches and gracefully reload FPM according to the runbook. Verify current confirmation readiness/config remains identical.

Code rollback should retain the additive table and saved schema-2 records rather than dropping data. Older code before this increment does not understand schema-2 definitions; retain the schema-2 isolation guards and UI filtering in any rollback candidate, or separately plan a reversible isolation of those records. Do not assume checking out the old release alone is a complete rollback once new definitions have been saved. No destructive down migration is prescribed.
