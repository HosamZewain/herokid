# Manual handoff and payment saves — 1 November 2026

Owner requested payment recording while Automation owns the checkout, explicit manual takeover to edit orders/upload approved identity, and optional return or manual Codex generation; explicitly authorized deployment.

## Behavior

- Combined workflow form accepts payment-only updates during Automation ownership. Under row locks, any actual order/printing/shipping change is rejected before recording money. Unchanged production fields are not written. Canonical ledger and staff attribution remain intact; repeated saves do not add another payment event.
- Existing handoff after approved identity and recorded money remains available. New explicit manual takeover uses existing orders.assignment.manage + orders.update permissions; Agent API accounts cannot take over. It pauses process runs and legacy confirmation attempts and creates one durable checkout hold before assigning the employee. All steps, provider receipts, images and payment history remain intact.
- The hold prevents enrollment, further process execution, confirmation sends/consumption and attachment-review polling. It survives assignment release. Source-held checkout merges require a held target too.
- Sending, unknown results, queued retries, identity generation and active Codex runs cannot be handed over mid-flight. Codex exposes its existing stop-and-acknowledge flow. No invented cancellation of an external request.
- Explicit return restores only saved states, extends the customer-wait deadline by the pause duration and does not resend accepted templates. It refuses changed checkout/identity/source/production data; staff can finish manually or start the existing manual Codex action instead. Re-planning an identity workflow after material edits is not implemented in this release.
- Manual Codex remains available during a hold for an approved identity and any actual paid amount >0. The Codex run takes exclusive ownership until completion/acknowledged stop; the prior identity workflow stays paused.
- Takeover, return and payment actions record the actual staff actor in the checkout timeline. No automatic general activation, existing-order replay or synthetic production mutation.

## Validation

117 related tests /648 assertions passed on isolated SQLite/PHP8.3; final handoff gate rerun7/58. Broader199 tests /1503 assertions passed on isolated internal-network MySQL/PHP8.5, including identity, confirmation, manual Codex, assignment, checkout and payments. Dashboard SQL tests require MySQL (SQLite lacks CONCAT); all passed on MySQL. Final handoff/Codex rerun recorded in deployment handoff. External requests blocked/faked with synthetic fixtures; production credentials/media were not mounted.

Vite built against this checkout's lockfile; manifest targets exist and previous hashed assets retained. PHP8.3 compatibility exercised by isolated tests/Pint. No native image generation, live payment, handoff or customer message test.

## Deployment and recovery plan

Apply only additive migration2026_11_01_203000_create_order_automation_holds.php. Whole transaction deploy/shipping locks; private checkpoint and fresh checksum-verified local/off-instance backup; drain four affected workers and scheduler, short maintenance for source/schema/route/view switch, preserve env/cached config, reload FPM then restore existing workers/timer. No Codex worker restart, resource provisioning or config changes.

Previous application1dc514cae09461aa8d4810f6e37acd79d8e5a231. Once a manual hold exists, older code does not understand it: do not blindly roll back and re-enable automatic sends. Preserve hold rows, paused statuses, run/version/history, provider evidence and payments; forward-fix or reconcile safely before restoring a compatible release. Never restore the old database or drop hold records.
