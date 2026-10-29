# Shipping report: employee SKU breakdown

Prepared, not deployed. Branch `codex/shipping-sku-breakdown`, based on deployed release `b88ae971c262c04fa9742cfd9385283720aa2a7c`.

## Behavior

The selected employee now gets a product/story table with three columns: product/story (recorded SKU when available), quantity, and net value. It aggregates the entire selected range, including orders beyond the 25-row detail page. An optional day narrows this table and the order details; period cards/daily totals retain the full range. Employee/date links land on this breakdown.

Order-level details remain available in a native collapsed disclosure titled `عرض تفاصيل الأوردرات`. Detail pagination beyond page one opens the disclosure to keep the requested page accessible.

Grouping uses recorded item type, product/story identity, variant identity and SKU. Missing variant foreign keys fall back to the saved variant snapshot, including its SKU/name. Titles are a fallback only when identity and SKU are absent, so renamed items with the same identity/SKU aggregate. Different variants remain separate even when a parent SKU is reused. No catalog SKU is invented, and no current catalog price replaces recorded line values.

The existing category-level discount allocation is preserved. Each category's net cents are allocated proportionally to its item lines with deterministic largest-remainder reconciliation. Grouping then sums those net amounts: the SKU total equals the selected checkout net total, excluding shipping. Unclassified historical money remains an explicit zero-quantity row. Financial permissions remain enforced.

## Verification

- 78 related tests / 504 assertions passed on SQLite and isolated MySQL 8.4.
- Coverage includes employee/day scope, all pages, repeated SKU with changed titles, separate sizes, archived snapshot variants, mixed stories/products, exact cents, zero-price lines, legacy/unclassified values and financial permission restrictions.
- PHP 8.3 lint, Pint and diff whitespace checks passed.
- Frontend build passed with the existing bundle-size advisory. Existing assets restored; manifest verified. No generated asset changes were needed.
- Synthetic Laravel render passed; Chrome desktop inspection verified the three-column table and opening/closing order details. Preview values are synthetic, not production evidence.
- Local evidence: `/tmp/herokid-shipping-sku-evidence/`.

No production data or configuration changes were performed. Deployment requires the owner's approval; then recheck actual AWS SHA/drift and use the narrow code-only runbook with a recovery checkpoint. No migration, dependency installation or worker restart is required.
