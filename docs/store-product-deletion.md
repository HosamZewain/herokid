# Permission-gated catalog product removal

Products can be removed from the Admin products list using the existing `store.products.delete` permission (Arabic: حذف المنتجات). Viewing or editing products does not grant deletion. The existing protected `DELETE /admin/products/{product}` route binds the product by slug; no new public or Agent deletion endpoint is added. Grant the permission deliberately through existing staff/role administration; this release grants nobody new access.

Expand “حذف المنتج”, enter a reason (3–500 characters), check the explicit confirmation, then submit. Both reason and confirmation are required on the server. A repeated deletion returns 404 without another deletion audit. The reason, employee, product ID, name and slug are recorded under `store.product.deleted` in the existing activity log.

Removal is a soft delete, not a physical cascade: the product is disabled and receives `deleted_at` in one transaction with its audit record. It disappears from ordinary Admin/catalog queries, public product routes, mobile catalog and new-purchase lookups. Product images, variants, production components, existing order items, snapshots, prices and payment events remain intact. Historical order-item relations explicitly include archived products, so existing production prompts still work. Existing order cancellation/removal/restoration stock adjustments also include archived products; this does not make them available for new purchases. This release adds no force-delete or catalog restore UI.

## Deployment

The additive migration `2026_10_21_000200_add_soft_deletes_to_products.php` adds a nullable timestamp only. Existing products are not removed or deactivated by the migration. The reviewed main deployment script includes this exact migration in its allowlist and runs it before reopening the site. Use the pinned release script; do not deploy model changes without the migration. Existing scheduler/queue setup is unchanged. Built CSS is committed for Hostinger; retained older assets keep already-open pages working.

Do not delete old product images or issue hard-delete SQL as part of deployment. Do not drop the new column as an automatic rollback; review code rollback and the deployment backup first, preserving intervening orders and payments.

## Verification

Tests cover hidden controls and 403 without deletion permission, rejection of customer access, server-side confirmation/reason validation, repeated requests, atomic historical-data preservation, public/mobile exclusion, existing production access, and stock release/reservation for an old order after catalog removal. Regression selection includes permissions, catalog/mobile commerce, Agent/Studio, product production, order edits/removal, and sales/order reports. All databases and browser accounts used for QA are disposable local fixtures; no production deletion is part of QA.

On 21 October 2026, the final selection passed **409 tests / 3464 assertions on each of isolated SQLite and MySQL 8.4**, with `APP_URL=http://localhost` explicitly set for deterministic signed-URL assertions. All 15 deployment/preflight tests, the production asset build, Blade/route caches, focused PHP style and syntax checks passed. Browser QA verified manager confirmation/reason/deletion, preserved historical order-item linkage, an unaffected second product, public exclusion, and hidden deletion controls for an edit-only employee. The new migration has not been run on production as part of QA.
