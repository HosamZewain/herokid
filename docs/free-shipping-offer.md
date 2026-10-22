# Website automatic free shipping

- Admin → Store → العروض وأكواد الخصم → عرض الشحن المجاني.
- Existing `store.discount_codes.manage` permission controls editing; changes are audited.
- One scheduled website campaign, disabled by default. Configure a positive minimum in EGP and required start/end in Cairo local time. Start is inclusive; end is exclusive. No new migration or production seed is needed.
- Eligibility uses server-repriced merchandise subtotal (stories, products, packages at their sale price), less coupon discounts, excluding delivery. Exactly meeting the minimum qualifies. All enabled delivery destinations are covered; there is no extra code or usage limit.
- Cart shows the threshold, remaining amount, original delivery charge, waived charge, and resulting total. Changes of address or cart contents recalculate eligibility. Open carts stop showing free shipping when the offer expires.
- Checkout independently reads current settings, recomputes eligibility and shipping, and snapshots `original_delivery_fee`, `shipping_discount`, `free_shipping_offer`, and net `delivery_fee` on the purchase group. This is a shipping waiver, not a merchandise discount or payment. Internal production orders share the same group totals.
- When the displayed delivery charge no longer matches the server (e.g. the offer was disabled while the cart was open), checkout asks for review and rolls back coupon redemption. Old clients without the optional `expected_delivery_fee` field still receive server-calculated totals.
- This campaign is website-only; existing Mobile API pricing and promo codes are unchanged.

## Deployment / checks

Use the existing pinned `scripts/deploy-main.sh` release process, including backup and cache rebuild. The feature does not enable an offer automatically.
After deployment, save a disabled campaign, verify the dates/threshold persist, and enable only for the intended campaign dates. Check below/at threshold and a changed shipping destination in the cart; do not place a production test order.
