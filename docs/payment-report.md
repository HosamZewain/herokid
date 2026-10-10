# Payment-date reporting (2026-10-10)

## One cash definition

`PaymentCollectionReportService` selects `OrderPaymentEvent` rows where
`affects_collection_stats = true`, bounded by `occurred_at` in UTC using Cairo
calendar dates. Amounts remain signed integer cents. Net collection is added
payments minus negative reversals/corrections. A negative adjustment is **not**
independent proof that money was actually refunded. Baselines and non-financial
merge adjustments are excluded by their existing ledger flag.

Financial report/card display preserves cents, while storefront price formatting
retains its existing presentation. Whole amounts omit unnecessary `.00`.

No clipping to the current order value, creation-date filter, cancellation
exclusion or soft-delete exclusion is applied to unfiltered period collections.
Old orders paid this month therefore contribute this month. Cancelled/deleted
orders retain their actual recorded movements. This release neither rewrites
the ledger nor backfills missing historic payments from current balances.

## Page contracts

- Dashboard daily payments and the last-seven-days table use the same ledger
  selector as the new payment report. Its active-order paid card is explicitly
  a **current balance**, not period collections.
- Sales report headline, comparison, cash trend and cash source/region/customer
  breakdowns use payment dates. Order counts, order values, average order value,
  remaining balances and the order table/CSV retain original-purchase cohorts.
  Separate exports distinguish current order balances from payment movements.
- Order report/list remain operational purchase-date reports; their paid columns
  and daily breakdowns are explicitly labelled current balances.
- Advertising report's `ordered` mode selects original purchases; `collected`
  mode selects purchases with payment movements in the period, including older
  orders. Its collection amount is period cash for its selected population.
  It intentionally excludes cancelled/deleted orders for marketing, so it is
  not a complete cash report. Order values/average still exclude shipping.
- Local analytics `revenue_today` remains today's order value with an explicit
  non-collection UI label. GA4 data, expense accounting and shipping event dates
  retain their independent meanings.

Sales cash non-date filters still apply. Status is the current checkout status;
payment-status filtering uses the state recorded **after the payment event**.
Item/type filters allocate each signed delta proportionally to current gross
item values; integer cumulative rounding preserves every cent. Shipping is
included in that estimated allocation. This is not item-level cash accounting.
Quantities identify the contents of each selected checkout once, not quantities
newly sold on every payment day.

## New payment report

`/admin/payment-report` and `/admin/payment-report/export` require the existing
`sales_reports.view` permission. No new permission migration is necessary.

- Defaults to this month; date presets/custom range, maximum 366 inclusive days.
- Daily net columns: stories-only, products-only, packages/mixed, unclassified.
  Each payment contributes once, inclusive of shipping. These columns sum to
  the daily net, and every day (including zero-payment days) is shown.
- Classification uses available live checkout contents, or retained history if
  wholly deleted. It is **not** a guaranteed immutable historic classification;
  edits can change category allocation but never the ledger amount/date.
  Missing item context is retained under unclassified, not dropped.
- Clicking a day shows signed movements, Cairo time, stable event ID, order
  reference/link, original purchase date, customer, contents, method and actor.
  Detail pagination uses 50 movements; totals always include all matching rows.
- Full-period/day CSVs preserve signed numeric amounts and escape user-supplied
  spreadsheet formulas. Export is audited, rate-limited and `no-store`.
- Compact contexts hydrate in batches of 200; no personalization/photos/prompts
  are loaded. There is no new stale result cache or per-payment relation query.

## Safety and production verification

No schema migration, environment setting, payment write or order workflow change
is introduced. Use the existing pinned main release procedure with backup and
config/route/view refresh. Do not run isolated database tests against production.

After deployment, select identical Cairo dates and **no advanced filters** in
dashboard, sales and payments. Compare payments CSV sum, daily footer and sales
net collection to this read-only ledger query (adjust dates):

```bash
cd /home/u470070883/domains/hero-kid.com/public_html || exit 1
/usr/bin/php artisan tinker --execute='
$start = App\Support\OrderDateTime::utcStartOfDay("2026-10-01");
$end = App\Support\OrderDateTime::utcEndOfDay("2026-10-10");
$q = App\Models\OrderPaymentEvent::query()->where("affects_collection_stats", true)->whereBetween("occurred_at", [$start, $end]);
dump([
    "net" => (clone $q)->sum("amount_delta_cents") / 100,
    "added" => (clone $q)->where("amount_delta_cents", ">", 0)->sum("amount_delta_cents") / 100,
    "reversed_corrected" => -(clone $q)->where("amount_delta_cents", "<", 0)->sum("amount_delta_cents") / 100,
]);
'
```

The user's earlier production query returned Oct1–10 **50,391.26 EGP**, split
Oct1–3 **13,348.00** and Oct4–10 **37,043.26**. These are a past observed snapshot,
not hardcoded report totals and not a guarantee of today's live values. No
production deployment/verification is implied by successful isolated tests.

## Release verification

- Isolated MySQL suite: **1,392 tests / 11,054 assertions**, zero failures/errors.
  The unrelated `DatabaseDumpRoundTripTest` was excluded after its test user
  lacked permission to create/drop extra restore databases. No additional
  database privileges were granted to work around that limitation.
- Payment report regressions on isolated in-memory SQLite: **12 tests / 83
  assertions**. Cases cover Cairo date boundaries, old/cancelled/deleted orders,
  signed corrections, item/category reconciliation, pagination, permissions,
  CSV safety, cent precision and bounded context-query counts.
- Runtime tests used PHP 8.5.5. Changed PHP files also passed PHP 8.2 syntax
  checks; this is not a claim of a complete PHP 8.2 runtime test run.
- Production asset build and isolated config/route/view cache compilation
  passed. Existing font-resolution/Browserslist/large-chunk build warnings
  remain unrelated to this report change.
- Local browser QA used a separate synthetic database, including desktop and
  390px mobile layouts, daily drill-down and an exported day whose rows summed
  to its displayed total. No production orders or payments were modified.

These checks do not replace the post-deployment ledger reconciliation above.
