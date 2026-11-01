# Story automation review — 2 November 2026 (Cairo)

## Verified production, read-only

Application SHA `7dafd2148ffb766e7cdaef1db7bd52f16b856b45` verified on AWS. Observations below were made 1 November22:00–22:12UTC /2 November00:00–00:12Cairo, not a synthetic end-to-end customer test. No production job replay, forced response processing, generation, payment, sending, or state repair was performed.

- New eligible checkout confirmation is enabled for all new orders after row2683; Arabic RoboDesk contract ready. An order-created observer registers a unique checkout outbox event and dispatches after commit. Website/mobile/admin checkout paths also emit transactional process events.
- Successful inbound confirmation changes checkout rows to `new_confirmed`; exact original template reply or unambiguous same-conversation typed confirmation is required. Outbound AI text is never confirmation. Full reads use50interactions, no after cursor while awaiting responses/payment review.
- Enabled workflow8/revision1 is authorized, all story IDs, trigger status_entered/new_confirmed, general rollout with floor0. It generates one identity per story, sends one combined identity/payment preview, waits48h for approval or revision, and then enters manual review. Timeout/revision is not approval.
-19 identity tasks succeeded,14 previews were delivered. No completed approval step was linked to a different approved attempt. No current new-confirmed story lacked a process run. No pending process events at22:03UTC.
- Identity approval changes story status to `costomer_confirmed` independently of money. A same-interaction attachment after the identity template opens a human review and `pending_review` for unpaid rows. All10 pending reviews had consistent display state on zero-paid rows. No receipt authenticity inference or amount is recorded automatically.
- Staff must check RoboDesk and record real money. Automatic scene eligibility requires an approved successful identity, eligible preproduction status, positive paid cents in the checkout with a paid status,13 complete scene texts, no manual hold/competing process, and no earlier Codex run. No50% minimum. No qualifying unenrolled stories found at22:08UTC.
- Two concurrent Codex jobs, one UUID/order/approved-identity hash per story. Each accepted scene is attached to its exact `story:<order_id>` on private S3. At22:04UTC,35 automatic outputs were read back and all matched SHA/order/unit/path. Existing manual completion separately verified in prior release.
-13 accepted scenes complete the run and set `ready_preview`. Internal review/text placement/Studio print export remain staff work; no customer scene-preview send or print/shipping effect is triggered here.
- Ownership is checkout-wide: a completed sibling may remain marked Automation while another sibling is waiting/running/quality-review. Manual payment is permitted while owned. Quality-review retains ownership until safely stopped/reviewed; not silently released as a completed story.
- Actual completed scene runs: order2654 automatic13/13,ready_preview; order2754 manual13/13,ready_preview and released. The former still has active siblings. Automatic action records have actor_nameAutomation, null employee user.
- Required seven services active, four relevant Supervisor workers RUNNING,81 historical failedjobs unchanged. No new external test calls; two bounded RoboDesk history GETs used for diagnosis only.

## Exceptions requiring review, not blind replay

- HK10-616: confirmation template accepted but delivery failed. Contact via another channel.
- HK10-621: send receipt is missing in HeroKid, event unknown. Direct read-only RoboDesk history found a delivered confirmation template in the attempt window with exact reference and identifiers. This is candidate reconciliation evidence, not authorization to resend or mutate the historical event; preserve it for explicit reconciliation.
- HK11-26: new three-row checkout, attempt became unknown at22:03:54UTC. Read-only RoboDesk history at22:12UTC contained no confirmation template in that attempt window. Absence does not prove no send; no automatic retry. Current generic catch does not retain the precise transport phase/HTTP failure, so cause is unresolved.
- New rows added to old/mixed-state checkouts (e.g.HK10-365/HK10-495) intentionally do not trigger a fresh checkout confirmation. They are not missed brand-new checkout sends.
- Two stories in HK10-597 need quality review: order2655/history story11/13; order2653/موعد النور2/13. Rejected image dimensions below1024shortside; no regeneration or rejected upload. Other stories continue.
- Customer replies are polled after minimum600seconds plus scanning/queue delay, not immediate callbacks. Public app success does not guarantee WhatsApp delivery, image quality or completion of every active run.

## Prepared timing addition — NOT DEPLOYED

The previous monitor shows rounded minutes measured at Laravel collection, not exact worker timestamps. This change adds nullable `codex_scene_runs.timing` plus worker wall-clock start/end and monotonic elapsed seconds. Shows HH:MM:SS per story in order and monitor; separates queue/preparation wait. Completed-worker average includes only successfully completed runs with worker terminal timing. Old observations are explicitly approximate and kept in a separate average. Failed/quality/stopped/live work never enters the successful average. A terminal clock is frozen; worker interruption without known termination does not invent a completion timestamp.

Worker measurement includes13 CLI invocations, validation and local staging; excludes queue/preparation before worker begins and Laravel/S3 collection afterwards. It is not the provider's internal GPU time. Unknown old durations cannot be retroactively made exact.

Deployment requires owner approval for this added change. Apply only additive migration, preserve existing outputs/env/config/hashed assets, refresh views. Dedicated worker needs a safe drain before refresh: fence new dispatch, keep collecting existing work, wait for actual CLI exit before restart, never interrupt current generation. New timing starts with the refreshed worker; in-flight/older jobs remain explicitly approximate. Recovery retains nullable timing/evidence and current queue; no replay or DB restoration.

Validation: full pipeline164tests/972assertions on isolated SQLite before timing;167tests/982assertions on internal-network MySQL including timing;29scene tests/138assertions SQLite;11fakeCLI Python tests; frontend polling test; PHP8.3syntax; lockfile Vite build byte-identical and26 prior assets restored. No production secrets in tests. No claim of fresh customer end-to-end testing or full visual print QA.
