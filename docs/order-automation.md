# Order automation — story identity v1

Admin: `/admin/order-automation`. Additive module; default draft “معاينة هوية القصص” is disabled and unpublished after migration, with generate/save only (no review gate or sending). `ORDER_AUTOMATION_ENABLED` defaults to false. Publishing does not activate. No old order is automatically admitted: activation captures a high-water order ID; scheduler scans only subsequent orders. Explicit trials can select an existing eligible order. Review and WhatsApp can be added/removed in later revisions; existing runs keep their original definition, and adding sending never retroactively sends previous previews.

## Supported workflow

Versioned story targeting (all/specific story IDs), one workflow run per story-order/child, configurable existing image model/provider, ordered photo subset (1–4), prompt variables, quality/dimensions, per-attempt exposure reservation, daily budget and concurrency. Typed steps: prepare → generate → save → optional review → optional WhatsApp → wait. Mandatory dependencies are validated server-side. Other product types need a new typed input/action adapter before publication; the first shipped adapter is story identity. No arbitrary HTTP/code execution is exposed in the editor.

Draft edits use optimistic versions. Publication stores immutable model/prompt configuration and disables the workflow until an authorized activation; configuration permission cannot bypass spending approval. Runs reference a revision and encrypt their prompt/photo/provider metadata at rest. Current credentials remain in the existing AI-provider credential store. Only allowlisted execution options are copied to a revision. Existing per-order identity prompt overrides are respected. Reference image bytes are sent separately; signed photo links are removed from the managed prompt block. Child/story/phone/photo checksums are checked again before attaching or sending. Results never approve identity, change payment, or start printing.

The `save` step creates a private `OrderAttachment` scoped to `story:{order_id}`, labeled as an unapproved identity preview. The admin order shows a link to its automation run. Manual identity approval and the existing customer workflow remain unchanged. Trial mode is generate/save only; WhatsApp must remain prohibited for trials even after the delivery adapter is implemented.

## Delivery dependency

The real RoboDesk identity-template contract has been requested from the owner and is not yet provided. The WhatsApp step currently stops with `delivery_not_configured`; no guessed API/event or freeform 24-hour reply is sent. `delivery_ready` is a hard false guard, not a browser-controlled flag. Enabling a workflow with WhatsApp is rejected until its adapter is implemented and verified. A provider accepting a send must not be described as customer delivery without receipt evidence.

Contract needed: endpoint/event, authentication, approved template name/ID + language, image/media requirements, ordered variables, response IDs, idempotency/reconciliation and error semantics. Use existing RoboDesk credential store when appropriate. Signed preview media should be short-lived, read-only, scoped to this run, and revoked when stale/cancelled. Never include original child photos in a customer template payload by accident.

## Execution and safety

Database queue `order-automation`; `order-automation:tick` runs every minute through the existing scheduler. Each job advances a step. Database leases last ten minutes; generation is persisted as `generating` before external submission. Expired `generating` becomes `uncertain` and cannot be reposted by recovery/retry. One external submission attempt; polling is separate. Existing Fal/Gpt image clients are reused. Fal callback/output hosts are restricted before fetching, output content is validated and stored privately. Duplicate live runs are prevented across workflows by the order identity key; request keys detect replay conflicts.

Daily reservations serialize on the workflow row; reserve the entire configured attempt exposure. Unknown charges retain reservation and consume concurrency. These are conservative exposure reservations, NOT actual provider invoices or guaranteed provider-side spending limits. Missing/incompatible pricing blocks generation. Budget is currently USD. Daily cutoff follows Laravel's configured timezone. Turning off workflow/global gate prevents subsequent automatic steps; it cannot cancel a provider request already accepted. Trials require explicit cost + processing-permission confirmations and recheck the initiating employee's access at execution. Unknown generation requires external reconciliation; there is deliberately no blind “generate again” button.

Polling can resume without resubmission. Attachment creation and advancing the save step are one DB transaction. If data changes, cancel the stale workflow and review the existing result manually. Input changes never overwrite current order data. No cleanup deletes historical outputs or order attachments. Cancellation preserves audit/history and files.

## Permissions

`order_automation.view`, `.manage`, `.activate`, `.execute`. Existing permission administrators receive view/manage only in migration, never automatic spending access. Trial/run operations additionally require `orders.view`; images, prompt details and execution require `orders.photos.view`. Assign activate/execute explicitly through existing roles. Normal customer/guest/Agent API tokens are not an alternative to the web admin guard.

## Hostinger deployment / isolated tests

Reviewed migration: `2026_10_22_000100_create_order_automations.php`; deploy-main allowlist updated. No composer dependency change. Existing main deployment backs up before migrations. Leave `ORDER_AUTOMATION_ENABLED=false` until an approved trial. No provider key or production order is required for the automated suite: HTTP fakes, synthetic photos, isolated SQLite and disposable MySQL only.

To process a deliberate trial, use an additional serial worker for the dedicated queue (or add it to the existing cron wrapper without removing default processing):

```sh
/usr/bin/php artisan order-automation:tick
/usr/bin/php artisan queue:work order-automation --queue=order-automation --stop-when-empty --tries=1 --timeout=240
```

The dedicated `order-automation` queue connection uses the existing database jobs table with a 600-second reservation, longer than the 240-second worker timeout. Existing database workers are unchanged. On Hostinger, add a separate every-minute cron `/bin/bash /home/u470070883/domains/hero-kid.com/public_html/scripts/run-order-automation.sh`; the committed wrapper uses a separate flock and never enables workflows. A dedicated worker avoids delaying the default queue. Tick schedules subsequent steps each minute; completed step logs show progress. Do not run test seeders or RefreshDatabase on production.

Post-deploy: verify admin navigation/direct-route permission, default disabled state, and migration. Run a no-cost simulation on an authorized order before any billable trial. Publishing does not perform provider or customer calls. Sending is intentionally blocked pending the contract above.

## Verification — 2026-10-22

- Broad order/admin/Agent/Studio/identity/RoboDesk regression selection: 389 tests / 3269 assertions, passed on isolated SQLite and disposable MySQL 8.4.
- Final save-only default and provider regressions: 91 tests / 600 assertions on both databases, including all 31 `OrderAutomationTest` cases. This final selection followed the request to make sending optional; it overlaps the broad suite above.
- 15 deployment-script tests; shell syntax, scoped Pint, diff whitespace checks, Vite build, config/routes/view caches passed.
- Browser QA on a dedicated synthetic SQLite app: permissioned login, module navigation, draft save, publication remaining disabled, successful zero-cost simulation, and a new workflow with review/send unchecked by default.
- No real paid generation, customer message, production migration, or Hostinger deployment performed during testing. Provider HTTP contracts were tested with fakes. Provider credentials/model compatibility and generated image quality still require an explicitly approved one-order trial before activation.
