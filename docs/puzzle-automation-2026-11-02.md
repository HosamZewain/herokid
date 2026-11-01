# Puzzle image automation — 2 November 2026

Owner authorized implementation, activation after confirmation and AWS deployment, then explicitly combined it with story timing release364ee2e. This release starts only on **future actual new_confirmed status events after the activation boundary**. No historical confirmed puzzle backfill or provider test is authorized/executed.

## Scope

Only catalog slug `personalized-child-adventure-hdf-puzzle` (verified production product41) qualifies. Puzzle books9/10/11 have no image-production prompt and are excluded. The product must resolve to exactly one canonical production component with quantity_per_item1. Physical copy quantity does not multiply image calls. Product/add-on units keep the canonical item/unit key, exact purchased variant, personalization and their own order's child photos; no checkout-level photo substitution.

The current product/component prompt is rendered using ProductProductionPrompt. Signed image references are replaced with a description; private photo bytes travel separately to the existing configured OpenAI client. The encrypted snapshot includes image checksums, rendered prompt, model/configuration, variant/item identity and pricing estimate. It is checked again before submission and publication. Existing output, missing photos, unsupported/multiple components, incomplete prompts, changed inputs or unavailable model stop without a provider request.

OpenAI gpt-image-2,1536x1024,high quality,n1,maxAttempts1,180-second request timeout. Configured estimate at catalog inspection0.165USD/image; not a billing guarantee. The0.50USD preflight bound is an estimate gate, not a provider spending cap. The foreground scheduled `puzzle-automation:tick` command dispatches a unique single-attempt job to the existing order-process-ai worker. Each job enrolls events and generates at most one image; timeout240s is below retry_after300s, with a durable processing/lease record and no provider retry. The scheduler stays responsive while the image generates. The uniqueness lock lasts at most600s after an interrupted job; a later tick marks an expired processing lease uncertain, never resubmits it. No new AWS service or queue worker. An independent cursor prevents the story workflow's processed flag from swallowing puzzle confirmations. Unique order_item_id prevents repeated confirmation or scheduler runs from generating again.

## Storage, review and ownership

Valid image: exactly one private attachment plus a separate private preview object, checksum readback verified on both writes. Production uses `s3_private`. Gallery deletion therefore cannot remove the attachment. Publish requires landscape3:2, minimum1536x1024, approved MIME/size limits. A dimension failure or late changed input preserves provider receipt and private output for manual review, with an authenticated order-scoped image link; it is not published as a validated preview. Visual similarity and print preparation still need the team; dimensions do not prove visual quality.

Automation is the assignee from pending confirmation-event evaluation through queued/processing/uncertain. Existing manual takeover can pause queued work; processing/uncertain work requires reconciliation first. A held checkout never generates. Completed/blocked/review results release this puzzle's ownership; another active story/confirmation process may still own the same checkout. All lifecycle transitions log Automation, exact item/unit and attachment/preview IDs. The order and automation pages show results/review codes.

No order-wide status change is made: changing a product row during an active mixed story workflow would invalidate that workflow's pinned checkout state. The independent puzzle status reports saved/review/blocked; existing story status, payments, shipping and printing remain untouched. **No product WhatsApp preview template is sent**, and no payment is recorded.

Unknown provider outcomes are never retried. Existing output and provider receipt are preserved even if publication fails. The preflight/processing/output/error stages are durable; there is no automatic historical replay or cleanup.

## Recovery

Disable only `puzzle_automation.enabled` to stop new calls; reconcile any processing/uncertain result before manual resubmission. Preserve tables,cursor,encrypted inputs,cost/receipt evidence and S3 outputs. Existing private config gains only puzzle settings, while story concurrency is restored after a drain. Do not reset the event boundary or delete runs to retry. Keep the additive schema on application rollback. Story timing worker replacement requires zero running CLI jobs and preservation of all existing bridge state.

## Validation

Synthetic fixtures only, external HTTP blocked: one call despite duplicate/reentrant execution, private refs, child/variant segregation, independent preview storage, activation floor, disabled mode, held/manual takeover, input changes before/after submission, low resolution, existing output, cancellation, delayed story states, expired leases and scoped authenticated image access. Regression tests cover confirmation,identity,payment review,manual handoff,canonical product components and Codex scenes. See dated runbook entry for final counts and deployment verification.
