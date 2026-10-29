# Shipping employee KPI report — 2026-10-29

Status: implemented and validated in an isolated branch; **not deployed**. Base: production release `9aec03a64cac749abc9109b4e5b6c0466592bb41`. Branch: `codex/shipping-employee-kpi`. No migrations, customer-data mutations, queue actions, or new infrastructure are required.

## Accepted business rules

- A qualifying checkout currently has shipping status **shipped or delivered**, including configured custom statuses with those behaviors. Returned, cancelled, created-only and not-ready shipping states are excluded.
- Credit belongs to the **currently assigned employee**, even when reassigned after shipping, as explicitly confirmed by the owner. Unassigned checkouts remain visible separately.
- The range uses the existing report's documented dispatch timestamp: earliest supported Bosta dispatch event, otherwise earliest local shipped-status log. Cairo dates are converted to UTC boundaries. Order creation, shipment creation, delivered-only events and arbitrary updated timestamps are not substituted.
- A checkout containing multiple child/order records counts once, with all active parts required to be shipped/delivered. A shipped-to-delivered transition does not create another credit. Merged source aliases are excluded.
- Qualifying records without documented dispatch dates are reported as an explicit global warning and cannot be assigned to a period. No historical dates are fabricated.
- This is a current-state report, not an immutable payroll ledger. Reassignment, returns, edits and payments can change historical-period results. It supplies counts and values; it does not determine or pay KPI amounts.

## User flow

Open `/admin/shipping-report`, select a shipping date range, and view a whole-period employee summary. Each employee name opens their filtered orders across the whole range. An optional day narrows detail rows. The employee summary remains labelled for the entire period; daily totals are labelled for all employees. Detail rows paginate by 25 with filters preserved.

Details include order link, documented shipping time/source, current shipping state, tracking number, current assignee, item titles/quantities, order total, item value, shipping, discount, current paid balance and remaining balance. No customer phone, address or private note is added.

Amounts reuse `OrderFinancialStatistics::checkouts()` for canonical grouped totals, with isolated handling for historical empty checkout keys. Shipping, discounts and paid balances count once per checkout. Paid balances are explicitly labelled as current balances, not collections during the shipping period. Values require existing `orders.statistics.view` or `order_reports.view` permission in addition to route access `shipping_reports.view`. Existing shipping-only access does not gain financial visibility.

## Validation

- SQLite: **73 tests, 447 assertions passed**, including 19 shipping-report tests and related order-report, checkout-statistics, payment-report, assignment and merge tests.
- MySQL 8.4: **73 tests, 444 assertions passed** on an internal Docker network with synthetic-only credentials/data. Final subsequent change only removed a duplicated currency label and added its HTML assertion; final SQLite run covers that correction.
- Covered reassignment, whole-period drill-down, optional day, unassigned orders, returns, mixed/partial groups, delivery deduplication, Cairo day boundary, Bosta date precedence, missing dates, custom shipped status, historical replacements, merged aliases, separate legacy balances, shipping/discount/partial payment accounting, overpayments, access controls, invalid filters, pagination and read-only behavior.
- PHP 8.3 syntax validation and Pint passed. Test runtime PHP 8.5.5.
- `npm run build` succeeded. Existing large HEIC chunk warning remains. New compiled CSS and updated manifest are included; historical hashed assets are retained for cached pages.
- Actual Blade output rendered with synthetic fixtures and visually inspected in Chrome at desktop width. General and employee-filtered screenshots saved outside Git. This was not a live production browser test or a mobile-device test.
- Focused regression suites passed; the entire application suite was not rerun on this independent shipping branch. The separate stabilization branch contains the previously documented six full-suite fixes.

## Release and rollback

Obtain production-deployment approval. Revalidate production HEAD and review any subsequent changes before combining releases. Deploy this branch's reviewed service, view, built assets and manifest using the existing release procedure; no schema changes. Validate authenticated date/employee filters and totals with read-only production requests. Do not create synthetic production orders or invoke external services. Rollback is a code/asset rollback to the prior release; no data reconciliation is introduced by this read-only report change.

The stabilization branch `codex/zero-cost-production-stabilization` is separate. No monitor timer, Telegram alerts, PHP-FPM configuration, or stabilization application fix is deployed by this report feature.
