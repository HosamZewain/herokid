# Add a package to an existing checkout

- Order group page → **إضافة باقة** (requires `orders.update`).
- Choose an active, available catalog package, its eligible stories, and one child for all components. Reuse the same customer's child from this or a previous order, or supply new data/photos.
- The shared form uses `PackagePersonalizationService`. Component photo limits remain independent; files are copied privately to new production records. `orders.photos.view` is required for uploads/reuse.
- `POST admin/orders/groups/{representative}/packages` accepts `pricing_package_id`, `expected_price_cents`, `story_ids[]`, optional `reuse_child_order_id`, `personalization[...]`, `photos[]`, `language`, `gift_note`, required UUID `request_key` and `change_reason`.
- The server resolves package contents, fixed variants, stock and price. A changed price returns 409 for review. One package per operation; repeat intentionally with a fresh key to add another.
- Adds normal independent story/product production records to the existing `checkout_group_key`. Each line retains `item_snapshot.package`. Package price is allocated by the same `PackagePricingService` used by storefront checkout; line totals sum exactly to the package sale price, including cent remainders. Do not recompute line totals by multiplying rounded unit prices.
- Existing purchased lines, approval states, uploaded assets, actual paid balance, shipping and checkout discounts remain unchanged. Total and outstanding balance increase by the package price. `checkout.package_added` logs actor, reason, request fingerprint, added item IDs and price snapshot. The payment-ledger transition is non-cash and has zero paid delta.
- Group locks serialize additions; retrying the same key/body is a no-op, conflicting reuse returns 409. Failure rolls back all new records/stock and removes only newly created photo files.
- Existing item count and legacy-price safeguards apply. No migration or historical-data rewrite is needed. Existing orders, payment recording, production approvals and printing flows are unchanged.

## Verification

Run `AdminOrderQuickPackageTest`, existing quick-edit/story/removal, package checkout and payment regression tests on isolated SQLite. Browser coverage in `tests/Frontend/order-quick-edit-browser.test.mjs` checks mobile layout, eligible story choices, child reuse, exact displayed price, no stale fields after changing the package, and retry keys.

After deploying the pinned commit, check `php artisan route:list --name=admin.orders.groups.packages.store`, then open an authorized order and inspect the picker. Do not save a synthetic package into a live customer order solely to smoke-test deployment.
