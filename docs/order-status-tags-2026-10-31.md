# Compact operational order tags — 31 October 2026

Deployed after explicit owner approval: `157e8faea212506f48f0fef99eda0f46f3dbf7a8`, branch `codex/order-status-tags`, based on `4b566cfb91d3da7b8795be0716163db19222ad41`. Deployment 31 October 2026 11:39:49–11:39:51 UTC / 13:39 Cairo.

Replaces tall operational cards with small colored, pill-shaped links. Active and unclaimed totals plus all dynamic status definitions share a single non-wrapping row; horizontal overflow stays inside that row on narrow screens. Labels remain readable, counts sit alongside them, keyboard focus scrolls each link into view. The explanation is collapsed below the row. Financial statistics, permissions, SQL/count logic and filter URLs are unchanged.

Validation: existing operational statistics suite 4 tests / 294 assertions passed on isolated SQLite with external HTTP blocked. Production Vite build passed and manifest references exist; previous hashed assets were preserved. Synthetic Blade preview rendered 15 current/illustrative chips at widths 1600 and 390: one row, 50px high, no page-width overflow, all chips actual filter links, last chip reachable using keyboard focus. Desktop/mobile screenshots visually reviewed. No new production queries or MySQL-specific code. No production mutation for UI testing.

Evidence: `/tmp/herokid-tags-preview/desktop.png`, `/tmp/herokid-tags-preview/mobile.png`. Preview contains synthetic counts, not live data. No service restart, migration or integration activation is required for a later approved UI deployment; use the scoped runbook procedure.

## Production verification

All seven changed-file hashes matched. .env/config hashes unchanged; FPM graceful reload and Blade cache only. No maintenance, migration, worker restart, messaging, queue or automation activation. Authenticated owner browser saw 15 tags, one row, approximately 49px high. Clicking under-review filtered 15 checkouts and reflected the chosen status; returned to general orders afterward. Deployed screenshot `/tmp/herokid-tags-release/deployed-tags.png` shows the filtered view. Public home/up/cart/new CSS 200; immediate 43-request log window had zero 5xx and zero Laravel errors. Failed jobs stayed 81; default/conversation queues had no waiting/reserved jobs, workers running, scheduler successful. Checkpoint `/home/ubuntu/herokid-tags-before-20261031`, archive verified, rollback code base `4b566cfb91d3da7b8795be0716163db19222ad41`.
