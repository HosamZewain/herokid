# Checkout statistics and calendar dates

## Shared purchase identity and creation date

One purchase is one `checkout_group_key`, not an order row, story, item, or
production unit. `CheckoutIntakeStatistics` selects `MIN(orders.created_at)`
across **all** rows of the checkout, including soft-deleted history. Adding a
story/product carrier, or deleting/replacing the earliest row, must not turn an
old purchase into a new purchase. Calendar boundaries use the configured Cairo
display timezone; timestamps remain stored in UTC.

Dashboard today's cards and yesterday comparison are derived directly from the
same seven-day dataset displayed in the daily table. Intake includes cancelled
and fully soft-deleted purchases. The default order-report lifecycle is `all`;
active/finished/cancelled catalog, status and permission filters still apply.

Dashboard recent purchases show up to 20 non-deleted checkouts, ordered by their
original purchase date descending, then original row ID descending for equal
timestamps. The date column shows that same original date and time in Cairo.
Deleted original rows still establish the date of a checkout with live rows;
fully deleted checkouts remain excluded from this operational recent list.

Order-list filters, order-report filters/CSV and sales-report period selection
use the same original checkout date. Order-report daily breakdown and sales
trends use that date too. Sales trends group dates in the display timezone, not
UTC. Historical daily **values** reflect the checkout's current item/discount
configuration, not an immutable historical sale-price ledger.

## Financial amounts

`OrderFinancialStatistics` remains the shared SQL financial calculator for
dashboard/list/local analytics. For historical intake it prefers active order
rows within a checkout. Only if the entire checkout is deleted are deleted
rows used. Superseded/deleted items in an otherwise active checkout must not be
added to its current item value.

- Order value includes shipping once and deducts the checkout discount once.
- Average order value = sum of `max(0, items value - checkout discount)` divided
  by the number of purchases in the selected population. Shipping is excluded.
- Legacy orders without item totals retain the existing snapshotted item-price
  / story-price fallback.
- Payments today and payment-operation counts retain payment-ledger event dates
  and actual deltas. Payments are **not** recalculated from order intake.

## Intentional differences between pages

- Operational active/order-status cards describe current workload, not new
  purchases. A filter or permission can intentionally narrow their population.
- Sales-report recognized revenue still requires actual collected money and
  excludes cancelled/deleted sales. It is not the same measure as total daily
  intake value. Type/item filters still narrow the sales item population.
- Local analytics purchases/funnel count checkouts, including deleted history.
  Local `revenue_today` retains its existing *order value* meaning (shipping
  included), using the shared financial calculation; it is not payment cash flow.
- GA4 traffic/campaign metrics remain external GA4 data; cart conversion and
  sharing reports use their own conversion/event dates, not order-row dates.
- Story/product quantity and customer saved-story-record counters remain
  record/item metrics, not purchase counts.

## Safety and deployment

The original statistics unification performs no data rewrite, migration, acquisition, assignment, status
change, payment update or asset operation. Existing rows and identities remain
unchanged. No environment variables or database migrations are required.
Clear the application/analytics cache when deploying to remove cached local
funnels calculated using the old record-count rule.

Regression coverage includes the 12-versus-15 screenshot shape, old checkouts
with newly added rows, deleted original rows, fully deleted/cancelled purchases,
multi-story identity, discount and shipping, Cairo midnight, list/report/CSV
dates, sales recognition/period/trend rules, legacy prices, empty dates,
open-ended/invalid filters and read-only behavior.

## Report performance release (2026-10-08)

- Order-report statistics reuse `OrderFinancialStatistics` scalar SQL checkout
  aggregates. Only the selected page hydrates report-specific order/item columns
  and minimal name/assignment/title relations. SQL pagination still operates on
  checkout groups, not on individual stories or products.
- Sales-report facts retain the existing PHP financial/recognition and item
  allocation rules. Matching checkout keys are processed in batches of 200;
  production prompts, photos, personalization snapshots and story content are
  not loaded. Only the selected page formats detailed customer/order rows.
  Source/amount filters are applied before pagination, and all matching compact
  facts still contribute to the statistics, not just the visible page.
- Cart attribution uses keyed lookups instead of a full cart scan per checkout.
  The existing first-matching-cart rule is preserved, including duplicate carts
  and multiple order rows for one checkout.
- Both CSV exports hydrate detailed rows in batches of 200 and avoid calculating
  unused on-screen breakdowns/options. Existing CSV columns and formula escaping
  remain unchanged. Exports are not immutable point-in-time snapshots: live
  edits while a long export is running can still affect later batches.
- Status definitions are indexed in process memory by type/key and invalidated
  by the existing status-settings save workflow. No new stale report-result cache
  is introduced; payment/cancellation changes remain visible on the next read.
- Equal sales timestamps/amounts now have an explicit stable checkout-key
  tiebreaker so pages and exports cannot shuffle tied purchases between loads.
- The additive `2026_10_08_000100_add_checkout_intake_reporting_index` migration
  adds `(checkout_group_key, created_at, id)` to `orders`. Run `artisan migrate
  --force` during deployment. It changes no order values or media. No new
  environment variables are required.

Regression checks compare SQL order aggregates to detailed rows across mixed
orders, cancelled/deleted/replaced history, custom lifecycle statuses, legacy
prices, overpayments, discounts, unknown workflow statuses, date/source/status
filters and empty results. Sales tests compare paginated statistics to complete
results, assert correct cart attribution and deterministic pages/exports, cross
the 200-checkout batch boundary, and verify read-only/immediate payment behavior.

## Shipping report (2026-10-08)

`GET /admin/shipping-report` requires `shipping_reports.view`. The new permission
is granted automatically only to existing active direct permission managers;
other staff can receive it through the existing permission/role administration.
The report is read-only and does not contact Bosta or modify assignments/statuses.

- Date filters use Cairo calendar days, including DST, with UTC database bounds.
  The default period is the last 30 days; the maximum is 366 inclusive days.
- One checkout is counted once, on its first recorded dispatch, not its creation,
  last edit, delivery, pickup booking, or airway-bill creation date. A carrier
  dispatch/in-transit event uses `occurred_at` and takes precedence over a locally
  synchronized shipping log. Without that event, the earliest shipping-status
  log whose configured behavior is `shipped` is used, including historical rows
  retained after an order edit. Repeated shipping updates do not double count.
- Delivered/returned orders with an earlier dispatch still count on their
  dispatch date. Current shipped/delivered/returned checkouts with no dispatch
  evidence appear in an undated warning and are excluded from daily counts.
  No dates are guessed from `updated_at` or a terminal event.
- Quantities are purchased order-item quantities, not distinct titles or
  production-component counts. Products, stories, and add-ons are shown
  separately; legacy stories without item rows count as one copy.
- Selecting a day shows shipment contents and aggregates each current order
  assignee's products/quantities. Unassigned checkouts remain visible. Contents
  and responsibility reflect current, non-deleted order rows, not historical
  immutable dispatch snapshots; this limitation is explicitly shown in the UI.
  Multiple parcels/re-dispatches of the same checkout are not separate purchases.
- Details are paginated at 25 shipments and item queries are batched by 200 keys.
  No photos, production prompts, addresses, payment details, or customer contact
  fields are loaded into the shipping report.
- Migration `2026_10_08_000200_add_shipping_report_permission_and_log_index`
  registers the permission and adds a covering shipping-log index. No new env
  variables or changes to carrier/order business behavior are required.

### Strict grouping compatibility hotfix

The first shipping-report release grouped directly by a normalized
`COALESCE(NULLIF(checkout_group_key, ''), CONCAT('order:', id))` expression.
MySQL 8.4 accepted that query, but MariaDB 10.11 with `ONLY_FULL_GROUP_BY`
reproduces error 1055 on `o.checkout_group_key`, matching the Hostinger report.
Checkout keys are now normalized in a derived query before aggregating orders
and historical shipping logs, so both aggregates group by an ordinary column.
This preserves empty-key legacy isolation, quantities, dispatch dates and
read-only behavior without disabling strict SQL mode or changing schema/data.
