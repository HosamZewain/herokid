# Employee performance report — 31 October 2026

Approved request: implement and deploy the new report. Base production SHA verified live: `77d2a21695e96f9de0e40744ef0b72ef2e5f71bf`. Implementation branch: `codex/employee-performance-report`.

## Access and report contract

New route `/admin/employee-performance`, linked as `تقرير أداء الموظفين` under Reports for active admins. Guests/customers/inactive accounts are denied. An ordinary employee sees only their own scoped data, including all totals, SKU details and undated warnings. Foreign employee IDs are rejected with 403; alternate/legacy query parameters cannot widen scope. The employee directory and comparison controls are not included in ordinary-employee output.

Full comparison is available to accounts possessing every registered permission, or an active canonical `owner` role together with `admin_users.permissions.manage`. Production owners intentionally lack three paid-automation permissions, so treating literal possession of every permission as the only full-access signal would incorrectly block their report. No role/permission records are modified and no paid automation is re-enabled. Generic `role=admin`, finance permissions, an inactive owner role, or a role name alone do not grant cross-employee access.

Financial fields retain the existing `orders.statistics.view` / `order_reports.view` gate; an employee without that access receives counts and quantities only. When allowed, amounts are net of the recorded discount and exclude shipping, with existing cent reconciliation.

Default date range is the current Cairo calendar month through today. Managers can select one, several or all employees, including inactive historical admin accounts and zero-activity employees. Unassigned orders are excluded. Attribution is to the current assignee, not the person who clicked a shipping button. Reassignment moves the metrics.

A checkout is counted once after all active parts are currently shipped/delivered. Date is earliest documented dispatch evidence, following the existing shipping report and Cairo DST-safe day boundaries. Delivered is the subset of those dispatch-period checkouts whose active parts are all currently delivered, including custom delivered behaviors. It is NOT a count by delivery-event date. Mixed shipped/delivered checkouts remain in transit until every part is delivered. Returned/unshipped states are excluded. Missing dispatch dates are explicitly warned about, scoped to the visible employees, never guessed.

UI includes shipment/delivered/in-transit cards, delivery percentage, quantities and permitted net value, an employee comparison table and matching-scale bar charts, zero-filled daily rows, and SKU breakdown for a single employee. No personnel grading or automatic KPI payment is introduced.

## Validation / deployment constraints

Synthetic tests cover manager all/one/multiple views, ordinary employee isolation/forged filters, hidden roster/SKU/undated data, financial permissions, owner role with automation withheld, guest/inactive accounts, assignment changes, split checkout statuses, zero data, validation, and the repeated winter-time hour. Existing shipping, permissions/roles, date, financial and report tests are run on SQLite and isolated MySQL.

The limited-staff sidebar expectation now includes this authorized personal report while other privileged report links stay hidden. An unrelated stale dashboard test label was updated to the already-deployed wording; dashboard code is unchanged.

Build committed frontend assets while retaining previous hashed files. PHP 8.3 lint and route-cache build must pass. This release adds a route, so regenerate the existing route cache during the guarded deployment (and rollback); preserve protected config cache, environment, credentials and permissions. No migrations, worker restarts, production test data, external customer actions, IAM/DNS changes or new AWS services.

Pre-deployment validation: 126 tests / 793 assertions passed on each of SQLite and isolated MySQL; PHP 8.3 lint, route-cache compilation and frontend build passed. The production read-only candidate probe reconciled employee/day totals, verified six ordinary employee scopes and rejected all six foreign-scope requests; comparison and rendering passed. Historical failed jobs remained 81. Deployment and post-deployment results will be recorded after actual verification. Local evidence: `/tmp/herokid-employee-performance-20261031/` and `/tmp/herokid-employee-deploy-20261031/`.
