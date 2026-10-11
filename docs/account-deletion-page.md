# HeroKid account-deletion request page

Public URL: `https://hero-kid.com/account-deletion` (GET); submission uses the same
path (POST). Linked from the shared footer and privacy policy. No extra account,
mobile token, email, support conversation or reason is needed to submit.

The form verifies existing email/phone + password and explicit confirmation.
Equivalent Egyptian phone formats match existing accounts; ambiguous legacy
duplicates and admin accounts are not accepted by the public form. Inactive
customers can request deletion after password verification, without restoring
access. CSRF, five-submissions/minute throttling, private no-store responses and
tracker exclusion protect this sensitive page. Passwords are never part of the
stored privacy request or confirmation receipt.

Both web and `/api/v1/privacy/requests` use `PrivacyRequestService`; a locked
user row serializes concurrent submissions. Pending/processing/in_progress
requests are reused, including requests already being handled by an admin.
The existing 30-day due date and requested/scheduled account timestamps remain.
No payment, order, production workflow, consent or file is deleted by submission.

## Operations and App Store scope

Requests are visible in **Admin → Mobile operations → Privacy requests**.
The website creates a request, not an automatic deletion executor. Existing
admin controls can start processing or reject; they deliberately cannot mark a
request completed without actually removing data. Staff must use a verified
manual privacy procedure to remove the account and non-required related data,
revoke sessions/devices/tokens, review order-linked media and notify the customer
after execution. Keep only necessary financial/legal/fulfillment records and
explain the reason/duration of any retention, including backups. Do not retain
child media merely for reorder convenience after account deletion.

The 30-day value is an existing processing target, not evidence of an automatic
scheduled deletion job. Before store submission the operational owner must
confirm the procedure can meet the displayed timeframe and retention disclosures.
This release does not implement a destructive deletion job or change order
approvals/printing/payments. It must not be described as proof of complete
App Store compliance or approval.

The iOS account settings must offer **Delete account** directly, using the
existing authenticated privacy API or a direct link to this page. A general
support/email-only route is insufficient. Official Apple guidance allows manual
execution with an explained reasonable processing time and completion notice:
https://developer.apple.com/help/app-review/guideline-reference/5-1-1-account-deletion

## Deployment verification

No migrations or new dependencies. Build assets locally; publish only this
scoped release through `scripts/deploy-main.sh` and refresh caches. After the
operator deploys, verify the URL returns 200, footer/policy links and the form
are visible, and cache headers include `no-store`. Validate successful submission
only with an explicitly authorized test account; never request deletion of a
real customer account as a smoke test.

## Release checks

34 focused feature/regression tests passed (232 assertions), using isolated
SQLite, including web/API deduplication, admin handling, authentication and
password reset. PHP 8.2 syntax, formatting, production asset build, and isolated
route/view caches passed. Browser QA at desktop and 390px mobile width confirmed
the form and receipt; a synthetic account request persisted as pending without
deleting the account. Missing CSRF returned 419. Production verification still
requires the operator's deployment and a live read-only page check.
