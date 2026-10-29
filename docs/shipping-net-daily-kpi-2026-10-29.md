# Shipping KPI: net values and employee daily breakdown

Prepared 2026-10-29; not deployed. Branch: `codex/shipping-net-daily-kpi`.
Base: shipping release `9efc343f07aa8aef7c121779ed9013528f73e693`. Recheck the actual AWS SHA and drift before an approved deployment.

## Behavior

- KPI values in the period cards, employee comparison, daily table and order details use canonical checkout item value minus the recorded checkout discount, floored at zero. Delivery charges are excluded.
- Selecting an employee scopes the period cards and daily table to the current assignee. The employee comparison remains explicitly labeled as all employees.
- Every date in the selected range appears, including zero-shipment days. Daily rows show checkout count, story/product/add-on quantities and their net values, plus a net total. A sticky footer shows period totals.
- Clicking a date filters order details; the employee's period summary and daily table keep the full selected range. Individual orders display net value and the recorded item value/discount.
- Checkout discounts are replicated across child orders; the canonical financial service reads them once. Already-recorded item prices are not discounted twice. The checkout net value is allocated proportionally across category gross values using integer cents and largest remainders (stable tie order: stories, products, add-ons, unclassified). Category totals therefore reconcile exactly to the checkout net value.
- Historical values without an identifiable story/item are preserved in an explicitly labeled unclassified column when nonzero. No classification, dispatch date or customer data is fabricated.
- Actual invoice paid/remaining balances retain delivery charges and are explicitly labeled; this reporting change never rewrites payments or ledger entries.
- Eligibility, checkout deduplication, Cairo dispatch-date evidence, current assignment and financial permissions are unchanged.

## Validation

All test data is synthetic and isolated. No production records, services, integrations, queues or media were changed.

- SQLite: 76 tests, 483 assertions, passed.
- Isolated MySQL 8.4: the same 76 tests, 483 assertions, passed.
- Includes shipping, checkout financial statistics, order report, payment report, assignments and checkout merges.
- New cases cover mixed categories with replicated discounts, employee/day/period scope, zero days, exact-cent rounding, free items, full/excess historical discounts, legacy saved story prices and unclassified amounts. Existing financial-permission and pagination checks passed.
- PHP 8.3 syntax validation passed; Pint and `git diff --check` passed.
- Frontend build passed. Manifest resolved; prior hashed assets restored. No resulting asset changes; existing CSS already contains the required utilities. Existing large-chunk advisory remains.
- Synthetic Laravel-rendered desktop preview passed (2 rendering assertions) and was visually inspected in Chrome. Preview figures are not production results. Static preview links are not a live backend; route/filter behavior is covered by feature tests.
- Local evidence: `/tmp/herokid-shipping-net-kpi-evidence/` (SQLite/MySQL/build logs and `daily-preview.png`).

## Deployment boundary

Application deployment awaits approval under the project runbook. This release needs only the report service and Blade view; no database migration, environment, IAM, DNS, payment, worker or scheduler changes. Follow the AWS runbook with an exact SHA, narrow recovery checkpoint, PHP-FPM graceful reload/view cache and authenticated read-only verification. Keep unrelated stabilization and mobile changes out of this release.
