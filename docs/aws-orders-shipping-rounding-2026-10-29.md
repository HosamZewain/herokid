# Orders performance and whole-pound discounted shipping — 29 October 2026

## Approved scope

The owner explicitly requested deploying both changes together. Base release: `8021c5b8bb8f6920fd7bce9e2d4bc69089e06304`. Branch: `codex/orders-performance-shipping-rounding`.

- Resolve the filtered checkout keys once before order-list financial statistics; retain the existing financial aggregation and filters. See the performance audit for measured baseline/candidate evidence.
- Round the final discounted shipping fee upward to a whole Egyptian pound, in integer cents on the server and in the cart JavaScript. Examples: 52.40, 52.50, 52.66 become 53; 52 stays 52. A 150-pound base fee with 35% discount becomes 98 instead of 97.50.
- Shared server calculation covers website checkout, mobile quote/confirmation, bootstrap delivery fees and RoboDesk shipping guidance. Disabled discounts retain original fees; free shipping and discounts reaching zero remain zero. Existing saved orders and idempotent completed checkout responses retain their historical amounts. Displayed/applied shipping savings are the effective difference after rounding.
- Old browser pages displaying an old fractional quote must review the refreshed price via existing checkout quote validation; never silently accept a mismatched expected fee.

## Validation before deployment

- 168 tests / 1,553 assertions passed on each of isolated SQLite and MySQL, covering discount/checkout, mobile quote/idempotency, existing orders, free shipping, grouping, order filters, financial/order/payment/shipping reports.
- Cart JavaScript tests cover rounding examples, 35% discount, exact integers, zero/free shipping, disabled discounts and repeated non-compounding recalculation.
- Frontend build passed. It produced identical tracked current assets/manifest after historical hashed assets were restored; the cart JavaScript is inline Blade.
- No dependency, schema, environment, IAM, DNS, FPM tuning or background-job changes. No tests against production and no customer/paid actions. No new AWS resources.

## Release gates

Use the canonical AWS runbook, exact base/target guard and a private verified code checkpoint. Graceful PHP-FPM reload and Blade cache are sufficient; no worker restart is required. Invalidate only the current RoboDesk guide page-1 cache key so the old fee guidance does not remain for its 15-minute TTL; do not flush shared cache or trigger an external ingestion.

After deployment, verify exact SHA/file hashes, services, read-only shipping configuration and all active delivery fees, orders controller/render timing, public HTTPS, authenticated order-list navigation, fresh errors and historical failed-job count. Record actual results in the handoff, not as assumed success here.
