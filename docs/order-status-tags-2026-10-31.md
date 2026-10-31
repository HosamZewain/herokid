# Compact operational order tags — 31 October 2026

Prepared branch `codex/order-status-tags`, based on live `4b566cfb91d3da7b8795be0716163db19222ad41`. Not yet deployed: this is a new UI change awaiting owner deployment approval.

Replaces tall operational cards with small colored, pill-shaped links. Active and unclaimed totals plus all dynamic status definitions share a single non-wrapping row; horizontal overflow stays inside that row on narrow screens. Labels remain readable, counts sit alongside them, keyboard focus scrolls each link into view. The explanation is collapsed below the row. Financial statistics, permissions, SQL/count logic and filter URLs are unchanged.

Validation: existing operational statistics suite 4 tests / 294 assertions passed on isolated SQLite with external HTTP blocked. Production Vite build passed and manifest references exist; previous hashed assets were preserved. Synthetic Blade preview rendered 15 current/illustrative chips at widths 1600 and 390: one row, 50px high, no page-width overflow, all chips actual filter links, last chip reachable using keyboard focus. Desktop/mobile screenshots visually reviewed. No new production queries or MySQL-specific code. No production mutation for UI testing.

Evidence: `/tmp/herokid-tags-preview/desktop.png`, `/tmp/herokid-tags-preview/mobile.png`. Preview contains synthetic counts, not live data. No service restart, migration or integration activation is required for a later approved UI deployment; use the scoped runbook procedure.
