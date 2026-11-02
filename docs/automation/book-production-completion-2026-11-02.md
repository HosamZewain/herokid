# Coloring books and combined preview — 2 November 2026

## Contract and owner decisions

Owner supplied three A4 cover examples: prominent recognizable child, colorful themed world, large exact name and coloring-book title, coloring motifs and HeroKid branding. These are style references only: no sample names, identities, ages or dedications are copied. Cover OpenAI; interior Codex; separate PNGs for Studio, no automatic print PDF. One combined message waits for every supported product preview and story identity. Scene-reading PDF previews remain separate.

Owner confirmed RoboDesk readiness: `herokid_preview_review_payment`, `ar-EG`, positional customer name / reference / immutable URL / numeric nonempty remaining balance (including zero). Quick Replies `تأكيد المعاينة` and `طلب تعديل المعاينة`; payment acknowledgement is not approval. No callback/new RoboDesk fields. Future checkouts and explicitly inspected currently confirmed checkouts are authorized, not retries of historical failed work.

## Implementation

- `accepts_new_runs` separates enrollment from enabled execution. Atomic cutover drains the previous preview workflow without disabling response polling or payment review. A checkout previously owned by any identity/preview run cannot enroll in the successor, even after its earlier run completes or blocks. Existing holds and immutable revision ownership remain.
- Catalog configuration creates independent cover/interior stages and explicit variant page counts. Old creative briefs stay inactive, not deleted. Converting an eligible unstarted order retires its old component snapshots with `retired_at`, preserving their text and IDs. A3 and unmapped variants require review; they never silently become A4. Edited historical briefs, attempted generations, manual holds and existing output prevent automatic conversion.
- A4 cover composition includes exact child name and Arabic title. The real repository brand asset is composited into reserved space. Original provider output is preserved; A4 paper padding never stretches/crops child pixels. Output records effective PPI and requires human print/name/identity QA; it does not claim native 300 DPI by enlarging pixels.
- Book protocol6 supplies checksummed original child photos, approved cover, and explicitly linked same-child story scenes in chronological order. Cross-checkout/different-child links are rejected. Linked story must be completed with current approved identity and fingerprint. Page plan contains all linked scenes before distinct additional activities.
- Each page stays bound to run/order/item/unit, is uploaded with private S3 checksum verification, and gets an A4 padded attachment while preserving raw bytes. Cover approval and any actual positive recorded payment are required. Receipt attachments alone never admit pages. Per-page bounded quality retries and later-page continuation remain; unknown provider outcomes are never retried. Sibling book failures retain pending-team-review status.
- Existing shared Codex collector was rejecting one-ULP MySQL JSON epoch rounding as a mutated attempt. Comparison now tolerates at most one microsecond for timestamps only, preserving outcome/attempt checks. This permits normal collection of already-produced images, not generation replay.

## Operations

`books:configure PRODUCT --default-pages=N --variant-pages=VARIANT:COUNT` inspects; `--apply` explicitly configures. Rollout defaults to read-only inspection: `order-process:preview-rollout OLD_FLOW ACTOR`. `--apply --cohort=EXACT_KEY` switches future enrollment and rechecks each explicitly reviewed checkout inside transactions. Never pass an unreviewed blanket cohort or change an old run to make it eligible.

Configuration flags remain independent: `PRODUCT_REVIEW_ENABLED`, `PRODUCT_REVIEW_TEMPLATE_READY`, `COLORING_BOOK_PAGES_ENABLED`. New pages require worker heartbeat protocol6. Refresh only after preserving in-flight work. Existing private cached configuration, old workflows, payment balances and pending jobs must be preserved.

## Verification / boundaries

Synthetic SQLite and isolated MySQL tests cover mixed preview/approval/payment, 20/40/50 variant snapshots, preserved historical briefs, nonmutating cohort inspection, no double enrollment, old replies after cutover, original-photo transfer, wrong-child rejection, A3 rejection, native-resolution evidence, partial-page handoff and manual takeover. Fake CLI tests cover protocol6 references/checksum rejection and existing story retry/concurrency behavior. PHP8.3 lint and isolated lockfile asset build are required. No synthetic production order or real provider test is used.

The AWS runbook records actual release, checkpoint, cohort disposition and enabled state after deployment. This design document alone is not evidence of activation or a complete live coloring-book lifecycle.
