# Order operational cards and dashboard order — 31 October 2026

Status: **prepared, tested, not deployed**. Branch `codex/order-operational-cards`, based on verified AWS release `4127efd5d644056d09b04df2eb086d037f2679c9`. Production still had that SHA at final read-only verification. Deployment approval for this new combined change has not been requested in the current brief. The previous employee-report deployment approval does not cover this change.

## Order page

Users with existing order-list access can see active tag counts and operational cards without `orders.statistics.view`. Financial summary remains null for those users and `OrderFinancialStatistics::summarize()` is not called. No permissions or customer/order data are changed. Existing per-order invoice/payment display and actions remain as before; this change exposes no new financial summaries.

- Active orders card: switches lifecycle to active, removes trash/page, preserves other filters.
- Unclaimed orders card: active orders without a group assignment, replaces assignment filter with unassigned, removes trash/page, preserves other filters. This means unclaimed by staff, not undelivered to the customer.
- One card per active order-status definition, including zero values, plus retired statuses with matching records and mixed status.
- Status cards use the existing status-registry background/text colors (including custom statuses), with readable labels and counts. Colored preview and a fresh production build were verified after the owner requested this visual follow-up; no query/permission behavior changed.
- Every card is a keyboard-accessible GET link. Status cards replace the order-status filter and reset pagination; all other filters remain.
- Counts represent each link's results. Each checkout counts once within a status; mixed checkouts can appear in multiple status cards. Status counts therefore must not be summed as a total. Other workflow filters retain the existing list's same-row matching semantics. Cancelled counts can be zero within the active tab; choose all/cancelled to include cancelled orders.
- Active tags retain existing facet behavior (ignore the currently selected tag only), including distinct checkout counts.
- No new cache layer, schema/index change or per-order query loop. Resolve checkout keys once and reuse them for aggregates/tags to avoid repeated nested MySQL predicates. Status rows fetch only distinct checkout key/status pairs.

## Dashboard

Below the existing welcome panel, the order is now: today, current month, current operations for all periods, last seven days, then the remaining original sections. Today's payment-event details moved directly before management notes and retain the existing sensitive-statistics permission guard. Apart from a relative-position description, existing section content and calculations are unchanged. The primary workspace's unrelated dashboard/mobile edits were preserved; this branch uses the actual live baseline.

## Validation

- 105 tests / 994 assertions passed on each of SQLite and isolated MySQL. Synthetic databases only; no production secrets or customer-media integration tests.
- New tests verify financial-summary absence/no financial aggregation; active tags for ordinary staff; grouped checkouts; current/custom/retired statuses; deleted/superseded rows; catalog/lifecycle/search/tag/assignment filters; zero results; owner access; and every card URL's result count across five filter combinations, including page reset and filter replacement.
- Existing dashboard permissions/notes/month statistics, employee report, order reports, multi-status filters and checkout financial tests passed.
- Additional isolated synthetic render check verified exact dashboard section ordering and payment details immediately before notes. Browser preview confirms count-only cards, status links, tags, and dashboard order.
- PHP 8.3 service lint and Vite production build passed. Manifest assets verified; historical hashed assets retained. Existing large-chunk Vite warning remains.
- Read-only candidate probe on AWS, using a real ordinary staff model in memory and a MySQL READ ONLY transaction with 5s statement limit, reconciled every status card against the actual list query for active stories and all orders/all lifecycles. Financial statistics remained absent. No sign-in/session impersonation, production PHPUnit, business writes, or external actions.
- Final service-only sample: active stories 397ms / 30 queries; all orders 281ms / 27 queries. Old staff baseline samples were 272ms / 26 and 130ms / 22 respectively. These are individual warm/cold-varying samples, not load tests or full browser timings. Initial nested-query design was slower and replaced before this prepared release.

## Deployment handoff

Pending explicit approval. Revalidate live SHA/drift, inspect exact diff, make private code checkpoint, transfer verified bundle and compare every changed-file hash. Narrow apply: detached checkout, graceful PHP-FPM reload, Blade cache; no routes/config/dependencies/migrations/worker changes required. Preserve config/environment/queue/DB/media state and unrelated historical assets. Validate authenticated order cards and dashboard ordering, normal HTTPS and post-release logs. Code-only rollback base is the verified pre-deploy SHA, with Blade cache refresh and graceful FPM reload; never restore a database for this view/report change.

Private local evidence: `/tmp/herokid-operational-evidence/`; isolated checkout `/tmp/herokid-order-operational-cards`. Synthetic screenshot/preview assets and runtime probe files are not part of the application release.
