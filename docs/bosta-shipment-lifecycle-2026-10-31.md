# Bosta shipment lifecycle and Pickup reliability — 31 October 2026

## Approved scope

Owner explicitly approved immediate deployment. This release also includes the checkout-wide story preview URL fix (f51d5d4). No real provider mutation is part of deployment validation.

- Preserve previous labels and events. Cancel a provider-confirmed pre-pickup label, then allow creation with current order/contact/COD values. Returned-to-business, lost and damaged attempts can be superseded only after a current provider read verifies their identity/state.
- Block uncertain create/cancel/Pickup outcomes. Do not automatically retry external mutations. Reconcile uncertain Pickup attempts only by exact provider shipment set, location and date.
- Match webhook provider identifiers exactly; old or superseded events cannot update a replacement checkout. Shipping KPI remains one checkout credit, earliest documented dispatch, current employee.
- Fetch available Pickup dates, normalize Egyptian contact phones, omit null notes, validate location/eligibility, persist attempts before sending. Shared lock and a unique active day/location slot prevent duplicate local requests.
- Automatic Pickup is configurable by permission managers, scheduled 14:30 Africa/Cairo except Friday. It selects eligible shipment-created checkouts at the configured location, checks provider dates/validity, and uses one daily request key. Unavailable same-day collection stops for review; it never silently moves collection to tomorrow.
- Configuration ships disabled. Following this explicit deployment approval, activation may use the verified owner account and the existing contact/location from the latest successful Pickup. Record actual activation separately below.

The original staff Pickup error was not recoverable from available logs and staff could not recall its text. No claim is made that a particular historical provider error was reproduced. New validation and retained attempts provide actionable diagnostics.

## Validation

96 tests / 746 assertions passed on each isolated SQLite and MySQL database, with external requests disabled. Includes multi-story preview URLs, shipment replacement and uncertain outcomes, old webhook isolation, report deduplication, available dates, permissions, schedule/timezone/Friday gates, exact Pickup reconciliation and duplicate prevention. PHP 8.3 lint passed. Frontend build passed with the existing large-chunk warning and no asset changes. Synthetic desktop/mobile browser checks: no JavaScript errors or horizontal overflow; available-date selector rendered correctly.

Read-only Bosta GET checks returned available dates and creation validity successfully. No shipment, cancellation, Pickup or customer message was sent during validation.

## Deployment and recovery

Base: 004be8a84d09597ce037ce3ce4e6d0ee7f742ed0. Apply only migration `2026_10_31_180000_support_bosta_shipment_attempts.php`, not all pending migrations. Requires fresh verified off-instance DB backup, route/Blade cache refresh and graceful refresh of existing workers because Pickup sync code changes.

Backup verified before deployment: `herokid-20261031T175412Z.sql.gz`, 41,168,972 bytes; local gzip/SHA256 and S3 size/checksum match. SHA256 `080ddcf7b1a9d0eadde7c061e3599577dc6e2fd5d9dd37e13d298dac593ce6ea`. No fresh restore test for this archive.

**Rollback boundary:** after any replacement exists, old code is incompatible with multiple shipment attempts. Preserve schema/history, disable automatic Pickup if needed and forward-fix or use a reviewed history-compatible rollback. Never restore the old DB or blindly roll back the migration. The migration refuses to drop shipment history.

Deployment result and activation will be appended after verification. No additional AWS resources are introduced; real authorized Bosta collections remain subject to the existing provider charges.
