# Scoped admin order editing

The checkout page now offers independent actions for adding a product, correcting checkout contact details, editing one product's personalization, and editing one story's child/language/dedication/notes or adding child photos. Each form has its own reason and save controls together in a compact, scrollable dialog. Validation errors remain inside the dialog and do not discard the form.

## Historical variant validation

The full editor previously required a current active product variant even when the purchased item predated variants and had no variant ID. It now preserves that historical choice and purchase price when the existing quantity is unchanged. Increasing quantity still requires a current variant selection. A new product always requires an available variant belonging to that product when variants exist.

## Scope and invariants

- All quick-edit routes require an authenticated admin with `orders.update`, using existing admin middleware and CSRF protection.
- Photo uploads/reuse additionally require `orders.photos.view`, matching the existing admin photo endpoint. Only protected admin photo URLs are returned; raw paths cannot be supplied by the browser.
- Child reuse is restricted to active children in the same checkout. The server resolves the child ID and photos; no phone/name matching or cross-customer guessing is performed. Submitted adjustments affect the new product only.
- Product adds reuse `AdminOrderUpdateService` product/variant/pricing/stock/component capture helpers. Existing items are not released, deleted, rebuilt or repriced. Personalized copies get independent new order/item identities; quantities greater than one use the selected child's details for each copy. Linked story add-ons retain their existing parent-child model.
- Existing payments are preserved in cents. Only subtotal, total, payment classification and outstanding balance are recalculated. The ledger records a balance adjustment with zero collection delta, not new cash received. Existing discount amount and shipping charge remain unchanged.
- Product personalization edits use the purchased schema when available. Only changed fields need validation; later catalog requirements do not invalidate a historical purchase. Existing item/component IDs, prices, statuses, previews, attachments and images remain intact. Supplemental photos are appended.
- A historical order row sharing one child's data among multiple personalized products is rejected by the single-product editor with an explicit message directing staff to the full editor; it is not silently split or allowed to affect another product.
- An old checkout whose financial value exists only in legacy delivery metadata, without matching item price rows, cannot receive a quick product add until repaired through the full editor. Its old value must never silently disappear from recalculation.
- Story edits reuse `OrderDetailsUpdateService`, including scene resolution and existing production-review safeguards. They do not change employee assignment, workflow status, unrelated stories/products or payments.
- Contact edits touch only parent name, primary phone and optional secondary phone, plus linked identity contact fields. Structured delivery mapping, address, attribution and product data are retained. Changed phone numbers must be valid local Egyptian or international mobile numbers; an unchanged legacy format does not block a name correction.
- Uploads use the existing configured private photo disk (local/S3). Failed transactions remove only newly uploaded images, never existing or shared photos.

## Audit and retries

Every write requires a reason (5–500 characters) and records the actor, time, subject, relevant item/checkout identities and before/after changes. Added products include their exact variant, quantity, purchase price and personalization snapshots. Photo events record filenames and counts.

Adding a product requires a UUID `request_key`. Successful keys and payload hashes are stored in the existing transactional audit log. Checkout row locks serialize duplicate attempts: an exact retry succeeds without a second item/stock/payment change; the same key with different data returns 409. Validation failures do not consume the key. The browser retains it for retries while the dialog stays open. Reopening the dialog starts a new operation.

No database migration, backfill, production mutation during reads, new environment variable or Agent API contract change is introduced.

## Verification

Laravel tests cover scoped edits, historical variant regression, permissions, stable production identities, balance preservation, stock, photos, rollback, duplicate retries and isolation between children/checkouts. Browser tests render the actual Blade dialog and load the production bundle on desktop and 390px mobile screens, with mocked endpoint responses; Laravel feature tests exercise the real write endpoints and database independently.

Release verification: `composer test` passed **1,075 tests / 8,502 assertions**. This includes 27 new scoped-edit tests and one historical-variant regression. Seven frontend checks (four browser + three logic tests) passed. Changed PHP files pass Pint and the diff passes whitespace checks. The production frontend bundle is committed, so Hostinger does not need to run Node to deploy this change.
