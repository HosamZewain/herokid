# Admin reports audit fixes — 24 October 2026

Base release: `2b05a6240c35a8f501df83083962ec107b019b09`.

This release changes report reads, definitions, filters and presentation only.
It does not modify customer payments, payment events, saved payment statuses,
order creation, production, shipping or checkout. There are no new migrations
or dependency changes. Existing hashed frontend assets are retained.

## Report-by-report result

| Page | Correction / verification |
| --- | --- |
| Site analytics | Conversion numerator and denominator use the same cart-creation cohort. Funnel counts distinct carts, not item events or unrelated admin orders. Period cart cards follow the selected dates. Local UTM conversions are separated from GA4 channel names, and local metrics are labelled as local. Zero bars have zero height. |
| App-download analytics | Existing visitor, view and click calculations re-tested; no algorithm change required. Clicks are not installations. |
| Advertising targeting | Parent product totals use the parent product name, with an explicit all-variants label. Cancelled/deleted orders remain excluded. Product targeting intentionally selects the whole basket, as explained on the page. |
| Sales | Includes retained deleted checkouts for all-status views; prefers live siblings and excludes merge copies. Empty retained checkouts preserve their financial amount without inventing pieces. Positive fully-paid balances are defined consistently with Orders/dashboard. Current saved payment status remains distinct from calculated balance. Average scope and negative/zero collection chart are explicit. |
| Payments | Canonical dates and non-date filters survive navigation from Sales, form submission, day details and CSV export. Saved payment status means the current checkout status, not its status after each historical event. Ledger amounts are unchanged. |
| Orders | Full-payment counts use positive balances covering non-cancelled/non-deleted order value, rather than trusting a saved status for zero/free orders. Added exclusive story-only, product-only, mixed and unclassified filters, retaining the existing contains-story option. Filter classification matches visible contents, not superseded deleted siblings. |
| Shipping | Existing dated shipment, quantity and employee calculations re-tested; no algorithm change required. Undated historical shipments are not assigned invented dates. |
| Identity sharing | Cairo date boundaries; all activity in the selected period, including activity on older/deleted shares. Table and cards use period events instead of lifetime counters. Referral values use financial checkout totals and expose current paid balance separately. CTR uses matching known visitor/share pairs; other rates have explicit denominators and scope. |
| Visitor carts | Summary cards use the same query filters as the table. The conversion denominator includes all matching statuses, including expired carts. Converted empty carts are not presented as zero-priced orders. Monetary fractions are preserved. |

The historical reconciliation panel is collapsed when balances match, expands
when there are differences, and is explicitly labelled as independent of the
report period and filters. It remains available without dominating each page.

## Important semantic boundaries

- Collection = signed payment-event amounts by payment date, plus documented
  dated recovered receipts. It is not the current paid balance of orders created
  during the period, and is not a bank/cashbox reconciliation after expenses.
- Current order value/balance and quantities use original purchase date and
  current/retained contents. Item filters in Sales/Payments show allocated
  amounts; Advertising product targeting selects whole baskets.
- Old balances without a documented payment date remain in all-time totals,
  not on an invented daily date. Missing historical shipment dates remain an
  explicit coverage limitation. This release does not manufacture evidence.
- Filtering by saved payment status does not rewrite that status. Calculated
  full-payment counts exclude zero-balance free orders and cancelled/deleted
  orders, and do not change any customer's payment records.

## Verification

- 228 Laravel tests / 2,108 assertions passed using isolated SQLite `:memory:`
  in a container with no network. Includes 16 audit regression tests, report
  exports and filter scopes, dashboard quantities, historical reconciliation,
  order merging, payment status workflows, permissions and performance tests.
- 74 financial/report tests / 484 assertions passed on a fresh MariaDB 10.11
  database in an isolated internal Docker network, with no production mount or
  connection. Includes the new filter queries and full-payment aggregates.
- 11 deployment tests passed; shell syntax and diff whitespace checks passed.
- Production Vite build succeeded. Configuration, route and Blade view cache
  compilation succeeded in an isolated testing container.
- Nine rendered report pages were inspected in Chrome with synthetic fixtures;
  checked signed chart, same-period tables/cards, filter labels, empty states,
  and collapsed/expanded reconciliation. The rendering fixture made ten
  successful route requests, including filtered payment day details.
- Regression assertions confirm report reads leave order balances/payment
  events and lifetime sharing counters unchanged. No live test orders,
  tracking events, messages or payments were created.

## Deployment and verification

Use the existing pinned `scripts/deploy-main.sh` from the verified main commit.
It backs up the deployed code/environment/database, fast-forwards main, verifies
committed frontend assets, rebuilds config/routes/views and restarts workers.
It must stop if the host has unreviewed local edits or main has moved.

After deployment, run the read-only command:

```bash
/usr/bin/php artisan payments:reconcile --json --limit=20
```

Then compare Sales and Payments using the same explicit start/end dates and
non-date filters. Check the period net against the sum of days and exported
movements. Compare Orders against the **operational order section** of Sales,
not the collection card. Include cancelled/deleted orders consistently.
Verify the four exclusive catalog filters sum to the all-types total, without
adding the overlapping contains-story option again.

Local test/build success is not proof of production deployment. Host output
and a fresh live report comparison are required after the operator deploys.
