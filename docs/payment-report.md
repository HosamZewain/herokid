# Payment-date reporting (2026-10-10)

## One cash definition

`PaymentCollectionReportService` selects `OrderPaymentEvent` rows where
`affects_collection_stats = true`, bounded by `occurred_at` in UTC using Cairo
calendar dates. Amounts remain signed integer cents. Net collection is added
payments minus negative reversals/corrections. A negative adjustment is **not**
independent proof that money was actually refunded. Baselines and non-financial
merge adjustments are excluded from dated movements by their existing ledger flag.
Verified, unsaved projections from pre-ledger admin activity use this same source;
see historical reconciliation below. Baselines are included once in the separate
all-time recorded-balance figure, not assigned their migration date as cash.

Financial report/card display preserves cents, while storefront price formatting
retains its existing presentation. Whole amounts omit unnecessary `.00`.

No clipping to the current order value, creation-date filter, cancellation
exclusion or soft-delete exclusion is applied to unfiltered period collections.
Old orders paid this month therefore contribute this month. Cancelled/deleted
orders retain their actual recorded movements. These reports neither rewrite
the ledger nor backfill missing historic payments from current balances.

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

No schema migration or environment setting is introduced. The read-only reporting
services do not change payments. The discount/status save safeguards below prevent
future unintended balance changes. Use the existing pinned main release procedure with backup and
config/route/view refresh. Do not run isolated database tests against production.

After deployment, select identical Cairo dates and **no advanced filters** in
dashboard, sales and payments. Compare payments CSV sum, daily footer and sales
net collection to this read-only shared-source query (adjust dates). It includes
verified pre-ledger movements, unlike a raw `order_payment_events` sum:

```bash
cd /home/u470070883/domains/hero-kid.com/public_html || exit 1
/usr/bin/php artisan tinker --execute='
$start = App\Support\OrderDateTime::utcStartOfDay("2026-10-01");
$end = App\Support\OrderDateTime::utcEndOfDay("2026-10-10");
$payments = app(App\Services\Payments\PaymentCollectionReportService::class);
$events = $payments->movementsBetween($start, $end);
dump([
    "net" => $events->sum("amount_delta_cents") / 100,
    "added" => $events->where("amount_delta_cents", ">", 0)->sum("amount_delta_cents") / 100,
    "reversed_corrected" => -$events->where("amount_delta_cents", "<", 0)->sum("amount_delta_cents") / 100,
]);
'
```

The user's earlier production query returned Oct1–10 **50,391.26 EGP**, split
Oct1–3 **13,348.00** and Oct4–10 **37,043.26**. These are a past observed snapshot,
not hardcoded report totals and not a guarantee of today's live values. No
production deployment/verification is implied by successful isolated tests.

## Earlier payment-date release verification

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

## Historical reconciliation and balance safeguards

The same global panel is shown in payments, sales, orders and advertising reports,
not on the dashboard. It is explicitly independent of period/population filters. Dated movement amounts
match for identical dates and filters; current order-cohort balances deliberately
remain separate and are not renamed cash receipts.

The dashboard retains its operational cards and Cairo-dated daily/last-seven-day
collections, but does not load the all-time reconciliation report. Verified old
baseline corrections are shown in a separate historical audit section, not in
the list of unresolved differences. When there are no actual differences, the UI
explicitly says that balances match; it does not display a zero-count review alert.
This presentation change does not alter payments, baselines or financial totals.

Dashboard cleanup verification: 120 focused tests / 766 assertions passed on an
isolated in-memory SQLite database (PHP 8.5.5), including dashboard permissions,
purchase ordering, daily collections, report parity and read-only legacy
corrections. PHP 8.2 syntax checks, Pint, `git diff --check` and the production
asset build passed. A separate synthetic browser database confirmed the panel
is absent from the dashboard and verified corrections are labelled as already
counted in financial reports. No production data was changed.

`HistoricalPaymentSource` reads immutable baseline snapshots and pre-baseline
admin payment/manual-creation logs. A complete old/new balance chain normally must
end at the baseline. The strictly verified deleted-carrier exception below handles
the proven original migration defect without rewriting that baseline. The initial
unproven balance remains undated. Broken chains,
duplicate baselines, overlapping ledger receipts and old editor revaluations are
flagged rather than fabricated as cash. Audit timestamps are receipt-recording
dates, not bank settlement confirmations. Zero baselines do not erase verified
old positive and negative movements. No projections are persisted; repeated
reads cannot create duplicate ledger rows. Internal negative IDs refer to audit
records; exports identify them as `activity:<id>` vs `payment:<id>`.

The all-time recorded-paid figure is **effective opening balances + subsequent
signed cash-event deltas**, NOT opening balances plus recovered history plus cash. The
recovered history only dates the proven portion of the opening balances; adding
it again would double count. Remaining undated balances are always shown.

`PaymentReconciliationService` explains the bridge to current checkout balances:
opening + signed cash deltas + signed non-cash balance adjustments = expected.
Merge aliases are followed to the current target; transfer events are not extra
money. Deleted carriers left at a merged source are excluded from current order
totals, while their actual cash events remain counted once. Non-cash subtraction
uses PHP signed integers, avoiding MySQL unsigned underflow. Every mismatched
group is flagged, including opposing differences that sum to zero. Nothing is
automatically written to hide a difference. This is NOT a cash/bank statement:
expenses, bank reconciliation and proof of actual refunds remain separate.

Discount changes preserve the amount received, including overpayments; only the
amount owed, remaining balance and appropriate payment status are recalculated.
Re-saving an unchanged settled payment status to change its method preserves an
overpayment instead of silently reducing it to the newly discounted price.
Explicit payment changes still follow the existing audited payment workflow.

After deployment, run the safe aggregate/per-order reconciliation:

```bash
cd /home/u470070883/domains/hero-kid.com/public_html || exit 1
/usr/bin/php artisan payments:reconcile --json --limit=20
```

This reads the live data without changing orders, receipts or activity logs and
does not print customer names, phones, credentials or message contents. The
command's opening/net/adjustment/current values and largest per-order differences
must be reviewed before claiming production financial reconciliation is complete.
The earlier raw-ledger query does not include recovered pre-ledger receipts;
the report source's dated movements and CSV must be used for historic periods.

The earlier supplied snapshot reconstructed 44,647 + 306,249.01 = **350,896.01 EGP** of
recorded opening balance plus net movements; adding 1,714 of non-cash changes
yielded expected balance **352,610.01**, leaving **2,711** against the screenshot's
355,321.01. The subsequent live reconciliation identified **1,434** of duplicate
merged-source balances, leaving **1,277** across HK08-4 and HK08-46. Those diagnostics
are past snapshots, not a claim about today's live balance.

## Earlier historical-reconciliation release verification (f7d971f)

- Full isolated MySQL 8.4 suite: **1,407 tests / 11,151 assertions**, no failures
  or errors. The unrelated native `DatabaseDumpRoundTripTest` was excluded; no
  production database was used or modified.
- Targeted financial/report/admin regressions on the isolated MySQL database:
  **177 tests / 1,691 assertions**. The final historical/payment/discount subset
  also passed SQLite: **38 tests / 287 assertions**.
- Runtime tests used PHP 8.5.5. The 14 changed/new application PHP files passed
  PHP 8.2 syntax lint, not a full PHP 8.2 runtime suite. Pint, `git diff --check`,
  production asset build and isolated config/route/view cache compilation passed.
  The asset build produced identical tracked outputs; no build files changed.
- Browser checks used a separate synthetic SQLite database with integrations
  disabled. Desktop and 390px mobile views showed the same global recorded-paid
  amount, the explicit reconciliation gap, and the selected day's signed total.
  No real orders, receipts or customers were created or changed.
- This release was subsequently deployed by the user. The supplied live
  reconciliation exposed the remaining deleted-carrier defect below; local
  tests alone did not establish its production cause.

## Verified deleted-carrier baseline correction

The supplied live audit/state diagnostics confirmed that the old migration used
`MIN(id)` across deleted and active items in a checkout. For HK08-4 and HK08-46 it
captured an obsolete, unpaid soft-deleted row instead of the active paid rows.
Continuous old payment logs recorded **499 EGP on August 8** and **778 EGP on
August 29**. Later zero-delta payment saves are not the receipt dates.

The reporting source now applies a read-only correction only if all evidence
agrees: a complete non-revalued activity chain, its initial balance equal to the
raw snapshot, nonzero recorded movements, the captured lowest-ID carrier deleted
before the last payment save, and the baseline's timestamp matching that save.
The end balance must also be independently anchored: either all rows active at
capture still retain its exact balance/payment timestamp, or the first subsequent
recognized immutable ledger transition for such a row confirms that end balance
in its **before** snapshot. A later legitimate payment or method save therefore
does not erase the historical receipt. The reader never skips an earlier
conflicting, unknown or backdated transition to find a matching anchor.

Duplicate baselines, overlapping real ledger movements, live or late-deleted
carriers, changed post-capture state without a matching ledger anchor, ambiguous
active-item balances, unknown opening credit and broken chains still fail closed
and remain visible for review. This is not a fallback that treats today's balance
as a dated receipt.

The immutable baseline, orders and audit logs stay unchanged. No receipt is
inserted and no migration is needed. The effective opening balance is the raw
snapshot plus its separately disclosed, signed proof-based correction. Recovered
activity movements provide the actual recorded dates, but are **not added again**
to the effective opening. Historical projections use the active carrier for
order links. Evidence is loaded in compact batches and cached only per request.

The shared panel in all reports shows the correction and its order, baseline
and activity-log references. The read-only command also returns `opening_snapshot`,
`historical_baseline_correction` and `historical_baseline_corrections`, preserving
existing summary fields and exposing the proof type and optional later anchor
event ID without customer personal data.

For the supplied unchanged live snapshot, the expected bridge after this correction
is **44,647 + 1,277 + 306,249.01 = 352,173.01 EGP** net recorded paid funds, plus
**1,714 EGP** of separately disclosed non-cash editor adjustments = **353,887.01 EGP**
current order balance, with **zero unexplained balance difference**. Proven dated
pre-ledger net becomes **43,541 EGP**; undated historical balance remains **2,383 EGP**.
Merge transfers of 7,293 EGP are not extra receipts. These expected numbers require
the post-deployment command to confirm; newly recorded live payments can change
them. They do not establish physical cash or bank balances, refund settlement or
expense totals.

After deployment, rerun `payments:reconcile --json --limit=20`. Verify both orders'
correction evidence and `different_groups`/`unreconciled`, then compare dated
payments and sales with identical dates and no advanced filters. Do not repair
the database or add 1,277 EGP as a new payment to force agreement.

## Deleted-carrier correction release verification

- Final isolated MySQL 8.4 suite: **1,416 tests / 11,233 assertions**, no failures
  or errors. The unrelated native `DatabaseDumpRoundTripTest` was excluded. No
  production database was used or modified.
- Final historical-reconciliation regressions on isolated in-memory SQLite:
  **21 tests / 159 assertions**. They cover the two proven receipts, original
  receipt dates rather than later status saves, once-only reporting and unchanged
  stored data, all shared report panels, signed reversals, bounded query counts,
  and later ledger anchors without accepting conflicting or unverified history.
- Runtime tests used PHP 8.5.5. All four changed PHP files passed PHP 8.2 syntax
  lint, not a complete PHP 8.2 runtime suite. Pint, diff whitespace validation,
  production asset build and isolated config/route/view compilation passed.
  The build produced identical tracked outputs; no public build files changed.
- Local browser checks used only a fresh synthetic SQLite database with
  integrations disabled. The shared panel displayed the correction evidence
  and zero reconciliation gap. August 29 drill-down and CSV showed a single
  **778 EGP** activity-sourced receipt at Cairo-local time. Synthetic order
  references are not production order identities.
- This update still requires user deployment and a fresh live reconciliation.
  Successful isolated tests do not establish today's production cash balance.
