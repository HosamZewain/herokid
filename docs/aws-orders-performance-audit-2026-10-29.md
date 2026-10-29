# AWS orders-page performance audit — 29 October 2026

Read-only investigation against live application SHA `8021c5b8bb8f6920fd7bce9e2d4bc69089e06304`, approximately 20:08–20:15 UTC. A minimal candidate fix is prepared in `codex/orders-index-statistics-performance`; it is **not deployed**.

## Confirmed primary cause

`AdminOrderGroupService::paginate()` passes the entire filtered checkout-key query into `OrderFinancialStatistics::summarize()`. The financial aggregate includes that filter within nested order/item queries. For the default active-stories order list this is the dominant cost, even though only 25 checkout rows are displayed.

Two production read-only samples in a consistent MySQL transaction:

| Phase | First sample | Second sample |
|---|---:|---:|
| Load/present 25 order groups without global statistics | 187.1 ms | 244.4 ms |
| Current nested statistics query | 6,147.4 ms | 7,497.9 ms |
| Resolve the same filtered checkout keys once | 46.5 ms | 41.0 ms |
| Same canonical statistics with the resolved keys | 49.5 ms | 54.2 ms |
| Statistics values equal in the same snapshot | yes | yes |

The resolved set contained 76 checkouts. Combined alternative statistics cost was approximately 95–96 ms, about 98% below the measured nested-query time. This is a query comparison, not a guaranteed whole-page speedup for every filter.

The active-products tab was less affected: current aggregate 234.8 ms versus 67.0 ms key lookup + 79.8 ms aggregate, with identical statistics over 150 checkouts. Filter shape materially changes the severity.

An authenticated Chrome navigation to `/admin/orders` took approximately 9.1 seconds of automation wall time; this includes browser/tool overhead and is not an instrumented TTFB measurement.

## Other observed contributors

- PHP-FPM remains `ondemand`, `pm.max_children=3`. Recent max-children warnings occurred through 19:53:18 UTC, including several since 19:26. Long requests can occupy all three slots and delay unrelated PHP pages. This confirms past saturation, not an active queue at the instant of inspection.
- Resources were not exhausted during inspection: load approximately 0.21 initially and 0.10 later; available memory 2.38–2.40 GB; root filesystem 43% used with 17 GB available. Three `vmstat` samples showed no active swap-in/out and CPU mostly idle. Existing swap occupancy alone is not proof of current memory pressure.
- Order-list rendering itself took 713–906 ms in the diagnostic CLI. A complete candidate render produced approximately 2.34 MB HTML, 14,553 DOM elements and 50 workflow panels for 25 displayed checkouts. The template renders both mobile and desktop representations, including hidden workflow forms. This is a secondary payload/rendering issue suitable for a separately scoped lazy-render or single-layout change. Synthetic gzip size was ~334.5 KB; this is not a measured production transfer size.
- Public requests were much faster: `/up` TTFB 201–293 ms, `/login` 211–288 ms, `/shop` 428–469 ms (two sequential samples each, all 200). This limited sample does not characterize all pages or peak load.
- The old repeated WhatsApp-settings-query issue is no longer present in this release: `OrderWhatsAppMessageService` uses `RequestSettings`, and profiling recorded one settings query for the 25 groups, taking under 1 ms. Historical audit findings were treated as leads, not current facts.

## Prepared minimal fix

Materialize only the distinct filtered checkout keys once, then call the existing canonical financial aggregator unchanged. No order/item hydration for all results, financial formula change, data cache, schema/index migration, new AWS resource, FPM change or paid service is introduced. This adds one small key-list query and avoids embedding the expensive filter in the financial subqueries.

A candidate file was loaded explicitly by a private diagnostic CLI process in `/tmp`; the web application and workers continued using the unchanged deployed service. With read-only production data and blocked external HTTP:

- Controller: 342.5 ms.
- Controller plus full Blade rendering: 1,216.8 ms (diagnostic CLI, not end-user latency).
- 31 queries, total DB time 249.7 ms, slowest query 55.14 ms.
- PHP peak memory approximately 44.7 MB.

## Validation and limits

- 129 related tests / 1,169 assertions passed on SQLite.
- The same 129 tests / 1,169 assertions passed on isolated MySQL 8.4.
- Coverage includes order index, multi-status/catalog/lifecycle filters, tags, grouping/merges, assignments, financial statistics, order/payment/shipping reports.
- PHP 8.3 lint and `git diff --check` passed. No frontend changes or asset rebuild required.
- Production probes used `SET SESSION TRANSACTION READ ONLY` and session-only SELECT timeouts (8 seconds for baseline, 2 seconds for candidate), array cache/session/mail configuration and blocked external HTTP. No global database settings changed.
- An initial render-only diagnostic omitted statistics while retaining an admin with statistics permission; it failed on the missing view data. The harness was corrected to pass the real computed statistics, then rendering succeeded. This was a diagnostic harness issue, not a production page failure.
- No production tests, customer actions, queue retries, service reloads, deployment or data edits were performed. Live SHA remained `8021c5b8bb8f6920fd7bce9e2d4bc69089e06304`; existing Git mode drift was unchanged; failed-job count remained 81.
- Key materialization scales with the matched checkout count. Current measured cohorts are small; reassess batching/derived-query strategy if future datasets approach database placeholder or memory limits.
- Evidence directory: `/tmp/herokid-orders-performance-20261029/`. Sanitized profiles contain timings, query fingerprints, table names and aggregate counts only; no bindings/customer data or rendered production HTML saved.

## Recommended sequence

1. Approve and deploy the narrow statistics fix using the AWS code-only release runbook, with a code recovery checkpoint and authenticated checks across filters.
2. Measure actual post-deploy navigation, errors and FPM saturation before deciding whether the previously prepared fourth FPM child is still needed.
3. Separately reduce duplicated/hidden order-list markup while preserving status, permissions and mobile/desktop behavior. Do not combine that larger UI change with this first fix.

The owner subsequently approved deploying this fix together with upward shipping-fee rounding; see the combined release notes. At the time of this diagnostic report the fix remained prepared. Existing runtime and infrastructure remain untouched; there are no new recurring AWS resources or services.
