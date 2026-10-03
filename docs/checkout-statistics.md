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

This change performs no data rewrite, migration, acquisition, assignment, status
change, payment update or asset operation. Existing rows and identities remain
unchanged. No environment variables or database migrations are required.
Clear the application/analytics cache when deploying to remove cached local
funnels calculated using the old record-count rule.

Regression coverage includes the 12-versus-15 screenshot shape, old checkouts
with newly added rows, deleted original rows, fully deleted/cancelled purchases,
multi-story identity, discount and shipping, Cairo midnight, list/report/CSV
dates, sales recognition/period/trend rules, legacy prices, empty dates,
open-ended/invalid filters and read-only behavior.
