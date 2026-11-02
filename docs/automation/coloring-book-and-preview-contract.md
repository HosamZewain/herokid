# Coloring-book stages and unified image review — prepared, not deployed

Prepared on 2 November 2026, based on live `f8ddc955c455d015a5d204a0b561b6cc1ec73e88` plus its documentation successor. No production catalog edit, generation, message, enrollment or deployment is included in this change.

## Implemented catalog and manual-message support

Products retain their existing production components. A component now has a role:

- `standard`: existing behavior, including current live-template updates.
- `book_cover`: exactly one image per purchased book; independent editable prompt. The product editor has an additive draft-cover button, preserving the existing interior prompt.
- `book_interior`: 1–1000 interior pages per book, excluding the cover. An active book needs exactly one cover and one interior component. Other independent products retain their existing component behavior.

`quantity_per_item` is the default interior page count for book components. A variant can override it using `book_page_count`; the numeric value is retained in `ProductVariantSnapshot` and captured on the order component. It is not parsed from product names or multiplied by the number of copies. The output-unit quantity still multiplies by purchased copies. `{{book_page_count}}` resolves to pages per book.

New book orders retain the component role, page count and prompts. Subsequent catalog edits do not rewrite their book prompts. Standard products retain existing live prompt behavior. Existing orders are not silently converted to a book workflow; the existing explicit order refresh action is the only way to import a newly configured component structure. Stable component keys and order-item identity are preserved.

`{{preview_links}}` is available in `/admin/settings/order-whatsapp-messages` for manual WhatsApp messages. It lists available child-identity images and the single checkout product-image gallery with Arabic labels, deduplicated. Identity images use signed links; galleries use existing private tokens. No PDF/scene-reader URL is included. `{{preview_scenes_url}}` remains the separate list of all story reader links. Existing saved templates are not rewritten automatically, and messages not using the new variable incur no additional review-link queries.

A pending process identity is included only if its current process guard succeeds. A failed/stale run is not presented as a ready identity. Rendering links is read-only: it does not approve identity, mutate payment, send a message or enqueue generation.

## Owner-approved target workflow (runtime still to implement)

1. Existing order confirmation.
2. OpenAI API creates only the coloring-book cover using the item/child references and cover prompt.
3. Save verified private S3 cover bytes as attachment and product preview.
4. Send the approved general image-review/payment template after the required identity/product previews for that checkout are ready.
5. Bind the response to the exact sent preview manifest: order IDs, item IDs, stable unit keys, image IDs and checksums. New/replaced images require another review; never let a gallery's later additions inherit an earlier approval.
6. Record preview acceptance separately from payment. Incoming attachments mean review only. Staff records actual payment; any positive valid payment is sufficient, not necessarily 50%.
7. Codex generates the snapshotted number of interior pages, using the approved cover as an identity/style reference. This requires a product-page job contract; the existing story worker intentionally still requires exactly 13 landscape scenes and must not be reused unchanged.
8. Verify and save each page on S3 under the correct production unit, record bounded retries/timing/failures, then hand off to the team. Manual takeover/holds and uncertain-provider safeguards must apply.

The component roles and labels are configuration, **not enforced automation/payment gates** in this patch. No new worker, activation switch, provider call or automatic sender is present yet. The current story and puzzle automations are unchanged. Full book runtime and common-review approval binding remain required; do not advertise this patch as an active book automation.

## Proposed common WhatsApp template — not submitted or approved

Proposed name: `herokid_preview_review_payment`, Arabic `ar` on Meta / `ar-EG` on RoboDesk.

Body (draft):

مرحباً {{1}}، دي المعاينات المتاحة لطلبك {{2}} من HeroKid.
برجاء مراجعة الصور وتأكيدها لاستكمال تجهيز الطلب.
المعاينات: {{3}}
المبلغ المتبقي: {{4}} جنيه.
لاستكمال الطلب، برجاء تحويل قيمة الطلب أو مقدم ٥٠٪ على محفظة 01111822277 باسم حسام جمال، وإرسال صورة التحويل هنا لمراجعة الفريق.
إرسال الصورة لا يعني اعتماد الدفع؛ الفريق هيراجع التحويل ويسجله.

Suggested quick replies: `تأكيد المعاينة`, `طلب تعديل المعاينة`.

Positional variables: customer name, checkout reference, identity/product preview links, remaining balance (number only). The manual variable can contain several lines. Before approving a Meta contract, verify whether the selected template accepts this representation; if not, use a single protected review-manifest page URL or an approved single-line representation. Do not silently truncate links. A frozen review-manifest page is preferable for binding approvals in mixed orders.

Existing `herokid_identity_review_payment` and `تأكيد الهوية` remain unchanged. The proposed common template is not registered in the live transport allowlist; an arbitrary common-template send is therefore rejected. RoboDesk will need tenant prompt instructions for the new exact button texts, with no payment approval claim.
