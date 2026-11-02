# Coloring-book stages and unified image review

Foundation deployed as48c2e4e on2November2026 at12:32UTC. Existing catalog/templates were preserved; no runtime activation. Runtime completion is included in the subsequent main release described below. Originally prepared on 2 November 2026, based on live `f8ddc955c455d015a5d204a0b561b6cc1ec73e88` plus its documentation successor. No production catalog edit, generation, customer message or enrollment is performed by this release.

## Implemented catalog and manual-message support

Products retain their existing production components. A component now has a role:

- `standard`: existing behavior, including current live-template updates.
- `book_cover`: exactly one image per purchased book; independent editable prompt. The product editor has an additive draft-cover button, preserving the existing interior prompt.
- `book_interior`: 1–1000 interior pages per book, excluding the cover. An active book needs exactly one cover and one interior component. Other independent products retain their existing component behavior.

`quantity_per_item` is the default interior page count for book components. A variant can override it using `book_page_count`; the numeric value is retained in `ProductVariantSnapshot` and captured on the order component. It is not parsed from product names or multiplied by the number of copies. The output-unit quantity still multiplies by purchased copies. `{{book_page_count}}` resolves to pages per book.

New book orders retain the component role, page count and prompts. Subsequent catalog edits do not rewrite their book prompts. Standard products retain existing live prompt behavior. Existing orders are not silently converted to a book workflow; the existing explicit order refresh action is the only way to import a newly configured component structure. Stable component keys and order-item identity are preserved.

`{{preview_links}}` is available in `/admin/settings/order-whatsapp-messages` for manual WhatsApp messages. It lists available child-identity images and the single checkout product-image gallery with Arabic labels, deduplicated. Identity images use signed links; galleries use existing private tokens. No PDF/scene-reader URL is included. `{{preview_scenes_url}}` remains the separate list of all story reader links. Existing saved templates are not rewritten automatically, and messages not using the new variable incur no additional review-link queries.

A pending process identity is included only if its current process guard succeeds. A failed/stale run is not presented as a ready identity. Rendering links is read-only: it does not approve identity, mutate payment, send a message or enqueue generation.

## Implemented runtime — activation separately gated

1. Existing order confirmation.
2. OpenAI API creates only the coloring-book cover using the item/child references and cover prompt.
3. Save verified private S3 cover bytes as attachment and product preview.
4. Send the approved general image-review/payment template after the required identity/product previews for that checkout are ready.
5. Bind the response to the exact sent preview manifest: order IDs, item IDs, stable unit keys, image IDs and checksums. New/replaced images require another review; never let a gallery's later additions inherit an earlier approval.
6. Record preview acceptance separately from payment. Incoming attachments mean review only. Staff records actual payment; any positive valid payment is sufficient, not necessarily 50%.
7. Codex generates the snapshotted number of interior pages, using the approved cover as an identity/style reference. Protocol 5 binds each page to its run, order, item, cover checksum and page number; story protocols retain their 13 landscape scenes. Book pages are portrait, at least 1024px on the short side.
8. Verify and save each page on S3 under the correct production unit, record bounded retries/timing/failures, then hand off to the team. Manual takeover/holds and uncertain-provider safeguards must apply.

The runtime adds `generate_previews` and `approve_previews` nodes and a ready-to-edit `product_review` preset. One immutable encrypted review manifest contains every required image. Its signed page has no approval or payment side effects. Unsupported products, stale inputs, unavailable outputs or any changed preview block the process for review; they are never silently omitted. Existing manual `preview_links` remains available independently.

`PRODUCT_REVIEW_ENABLED`, `PRODUCT_REVIEW_TEMPLATE_READY`, and `COLORING_BOOK_PAGES_ENABLED` default to false. Deployment leaves all three false. Existing confirmation, identity, puzzle/mug and story behavior continues. New covers are opt-in through an explicitly configured product and enabled workflow, not a historical catalog conversion. No historical job replay is included.

Book admission requires an approved latest manifest, unchanged cover/interior input fingerprints, active preview, actual positive payment and no manual hold or conflicting owner. An existing book run of any status prevents automatic re-enrollment. The existing two-slot Codex pool is shared; each run has a separate directory and item binding. Books on the same order serialize. If a story shares the same order, its completed story run precedes book pages. The exact immutable page count is used for collection, progress and completion. Pages are verified in private S3 and attached to the interior unit; no PDF or customer message is generated at this step. Completed runs become ready_preview; partial runs become pending_team_review with accepted later pages retained. Only definite image-quality errors get a second attempt; unknown outcomes/refusals do not automatically retry. Whole books are not replayed. Story timing averages exclude books.

Before switching workflows, finish/drain the old identity workflow, including waiting_staff reviews, and disable it; the activation guard rejects overlapping identity/common-preview owners. Existing customer approvals cannot be migrated blindly. Configure catalog cover/interior prompts and page counts explicitly; this release does not invent briefs or convert existing orders.

## Common WhatsApp template — submitted 2 November 2026

Name `herokid_preview_review_payment`, Meta ID **1670211884633726**, Utility, Arabic `ar` on Meta / `ar-EG` on RoboDesk. Submission was verified in Meta as **pending review**, not approved. No customer message was sent. Four POSITIONAL values: customer name, checkout reference, **one signed frozen review-manifest URL**, remaining balance as a number only. Exact Quick Replies: `تأكيد المعاينة`, `طلب تعديل المعاينة`.

Body:

مرحباً {{1}}، دي معاينة لطلبك {{2}} من HeroKid.
الرابط فيه هويات أطفال القصص وصور المنتجات أو كفر كتاب التلوين، حسب محتويات طلبك:
{{3}}
برجاء مراجعة كل الصور واختيار «تأكيد المعاينة» أو «طلب تعديل المعاينة».
لاستكمال الطلب، يرجى تحويل قيمة الطلب أو ديبوزت بحد أدنى ٥٠٪ من إجمالي الطلب على المحفظة الإلكترونية.
المبلغ المتبقي على طلبك: {{4}} جنيه.
رقم المحفظة: 01111822277
باسم: حسام جمال
بعد الدفع، يرجى إرسال صورة التحويل هنا ليراجعها فريق HeroKid قبل استكمال الطلب.

Existing identity template/buttons remain unchanged. New transport allowlist entry is gated before any conversation close/send. Before activation, verify Meta approval, RoboDesk catalog synchronization and tenant prompt responses for both new buttons; no callback, classification or payment approval is requested. Suggested acknowledgement: «استلمنا موافقتك على المعاينات 💛 هنراجع الدفع قبل استكمال الطلب». Revision: «استلمنا طلب تعديل المعاينات 💛 ابعتلنا التعديلات المطلوبة عشان الفريق يراجعها». Payment receipt acknowledgement remains review-only. HeroKid continues full-window inbound polling and message-ID deduplication, retaining existing exact sent-wamid matching and safe interaction fallback.

## Verification and boundaries

Synthetic tests cover combined story/product readiness, approval replay, changed cover/interior blocking, receipt-only pending review, any positive real ledger payment, protocol mismatch/manual holds, per-item output correlation, private storage checksum collection and partial-page handoff. Python fake CLI tests cover variable page count, quality retries and continuing later pages; no real provider request was used. Physical print/layout quality is still a team review, not implied by PNG/dimension checks.

Recovery must preserve manifests, encrypted inputs, media and attempts. Never run migration down or restore old database contents. A previous worker cannot accept protocol5; drain before refreshing or rolling back. New feature flags may stop future admission but do not reconcile active provider outcomes. Follow the AWS runbook and exact main SHA.
