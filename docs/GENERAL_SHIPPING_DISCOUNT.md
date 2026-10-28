# General shipping discount

Admin: `/admin/discount-codes` → **خصم عام على الشحن**.
Saving uses `PUT /admin/discount-codes/shipping-discount`, guarded by the existing
`store.discount_codes.manage` permission and CSRF. Every change is audited as
`shipping_discount.updated`. View-only users cannot edit the setting.

The setting is off by default, has no minimum order or schedule, and persists until
disabled. Choose fixed EGP or percent (0–100%, at most two decimal places).
The original country/governorate fees remain unchanged. A null governorate fee
continues to fall back to its country; an explicit zero remains zero.

`general_shipping_discount` stores `{enabled, type, value}` in settings. Value is
integer cents for `fixed`, or basis points for `percent`. Percentage discounts are
rounded to the nearest cent, then deducted; final shipping is clamped to zero.
Example: 30 EGP off 100/150 gives 70/120; 30% off gives 70/105.

## Integration and preservation

- Website cart renders original shipping, saving and final shipping. Recalculation
  always starts from the base fee. Checkout recalculates server-side and rejects a
  stale expected fee; client-supplied fee/discount amounts are never authoritative.
- Existing website free-shipping eligibility is unchanged: if eligible, final fee
  is zero. The total shipping saving cannot exceed the original fee. Coupon discounts
  remain on merchandise only.
- Mobile bootstrap fees, checkout quote and confirmation use the same general
  discount. A changed quote total requires a new fingerprint. The existing separate
  website-only threshold free-shipping offer is not newly enabled for mobile.
- RoboDesk governorate fees reflect the general discount; setting edits invalidate
  the feed cache through existing model observers.
- New orders snapshot original/final shipping and saving. Previously placed orders,
  payments and governorate base rates are not rewritten. Idempotent mobile retries
  return the completed order's original response even after the offer changes.

## Verification — 2026-10-28

- 124 Laravel tests / 3,221 assertions passed on isolated SQLite with network disabled:
  shipping, discounts, cart/checkout, shared-child packages, mobile commerce/quotes,
  guest checkout, feeds and payment/sales/order report regressions.
- 22 Node tests passed (7 cart calculation cases + 15 deployment safety tests).
- Production build and config/route/view cache compilation passed. No asset changes
  were generated; older assets removed by Vite cleanup were restored.
- Browser QA on a disposable localhost database: saved fixed 30, selected a 150 EGP
  governorate and saw 120 shipping / 320 total for a 200 EGP product. Changed to 30%
  and saw 105 shipping / 305 total. No live order or live discount was created.
- Two older photo-copy assertions in CartCheckoutTest were aligned with the already
  shipped config-based labels; no photo behavior changed in this release.

No new migrations or dependencies. Use the pinned `scripts/deploy-main.sh` flow
(backup, fast-forward, rebuild caches). After deployment verify the new admin form
is initially off and `artisan route:list --name=admin.discount-codes.shipping-discount`.
Only activate a discount when the administrator chooses its value. Production
behavior remains unverified until the user deploys and checks it.
