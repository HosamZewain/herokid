# Advertising targeting report

Read-only admin route: `/admin/advertising-report`, beside Sales Report. Both page and CSV exports require the existing sensitive `sales_reports.view` permission. No new permission grants, database schema, credentials, third-party requests, or scheduled jobs.

## Geography and purchase evidence

- Default last 30 days, original checkout creation date (including deleted history), Cairo-local date boundaries; reuse `SalesReportFilters` and `SalesReportService::rows(detailed: false)` for financial/status/customer facts. Current deleted records and any checkout classified cancelled by Sales Report are excluded. An edited order is not a new purchase.
- Default basis: all non-cancelled purchases, including unpaid. Optional collected basis includes only purchases with actual positive payment; order values still represent full order values, not collected revenue.
- Country/governorate from saved delivery snapshot; Bosta `city` is governorate, `zone` is city/centre, `district` is detailed district/neighbourhood/village. Group on saved IDs with normalized label fallbacks scoped under their parents. No current Bosta network lookup or street parsing.
- Historical `delivery_details.city` is displayed as a manually entered city/area, **not** an inferred official city. Unknown city/centre stays an explicit unknown group. No address, street, name, phone, or customer identifiers are exposed.
- Area ranking shows unique checkouts/customers, quantities, net order value, average order value excluding shipping, paid/delivered counts, within-period repeat customers, and top 3 item quantities. Customer identity uses the existing Sales Report user-ID/hashed guest-phone policy; guest and registered identities are not guessed/merged.
- Repeat customers are repeat purchases in the selected area/filter/date range, not lifetime retention. Coverage counts are after basis/location-search/item filters, before area drill-down. Excluded-cancelled count is for the full date period. Location search matches saved country/governorate/city/district labels literally; it performs no external geocoding. Editing a date switches the UI to custom range automatically.
- Item filter selects **whole baskets containing the item**, retaining full basket totals and showing co-purchased items; not item-only revenue. Product/story stable catalog IDs prevent equal names being merged; legacy ID-less items use type/title fallback. Snapshot titles and prices are retained. A product renamed over time is displayed with its first matching snapshot title.
- Item net values allocate the checkout discount proportionally to gross line values using cumulative integer-cent allocation; allocations sum exactly to checkout net value. They are not payments. Paid amount is reported separately and includes shipping and partial payment. Shipping/discount are counted once per checkout.

## Attribution and exports

- Reuse `MarketingAttribution::forOrder`, prefer saved evidence, converted-cart fallback for older purchases. First available evidence in the checkout is used. Campaign/adset/ad names and IDs plus UTM source/medium/content are grouped deterministically. Missing evidence stays unknown, never assumed direct. `fbclid` alone does not prove a paid ad.
- No Meta API, estimated ad spend, CPA, ROAS, or conversion rates. Area labels must be checked against ad-platform targeting locations. Samples below five checkouts are flagged, not automatic budget recommendations.
- Separate BOM UTF-8 CSV exports: areas (all ranked areas), items and campaigns (selected area, or all if none selected), preserving date/basis/item selection. Aggregates only, private/no-store, export audit and throttle. Spreadsheet formula prefixes are escaped, including whitespace prefixes.
- Three independently paginated tables (25 rows each); top-item previews bounded. Reuse existing bounded 200-checkout hydration and eager-load extra snapshot/cart evidence in 200-checkout batches, never one query per purchase. No comparison-period work or detailed customer rendering is requested. The report is uncached to avoid stale production numbers.
- Invalid area/item selection yields empty details instead of silently widening the result. Changing the geographical level in the UI clears the area selection.

## Release

No new migration or environment variable. Includes previous main migrations (notably database export migration) if that release has not yet been deployed. Build admin CSS, run report regressions and full suite. Deploy latest main with usual tracked-diff guard, explicit remote-main fetch, Composer, Artisan migrations/cache rebuild. Never deploy or mutate live orders as part of report verification.
