# Admin app notifications

Admin → Settings → App notifications (`/admin/mobile-push`). Existing
`settings.mobile.view` permits viewing/search/results; `settings.mobile.manage`
is additionally required to preview or send. No automatic broadcast occurs on
deployment. Never include sensitive child data in lock-screen copy.

Search and select one active customer, or choose all eligible customers. A
registered, non-revoked device with a push token and the corresponding
marketing/operational opt-in is required. Marketing is the default; do not label
advertising as operational to bypass consent. Preview records server-side copy,
audience, destination and count for 15 minutes. A separate explicit confirmation
queues the campaign. Repeated confirmation of the same UUID is idempotent.

The default queue worker dispatches recipients in chunks, one notification per
campaign/customer. Device preferences are rechecked immediately before sending.
Retransmission after retries skips provider-accepted delivery records; a shared
notification lock prevents concurrent duplicate send jobs. A connection timeout
can leave provider acceptance unknown; retries cannot guarantee exactly-once
delivery across an external service boundary.

Results distinguish queue preparation, provider-accepted (`sent`, not confirmed
device delivery), provider failure, pending attempts and in-app read time.
`mobile-push:check-receipts` runs every ten minutes via the existing scheduler,
checking tickets aged 15 minutes to 24 hours (at most 100 per run). It records
FCM/APNs provider acceptance/failure, not on-device delivery. Missing receipts
expire to unknown after 24 hours. A later DeviceNotRegistered receipt revokes
only the same token hash used at send time, not a newly registered replacement.
One customer can have several devices. Delivery
tokens and raw provider error strings are not displayed. Revoked device tokens
are cleared on DeviceNotRegistered. No eligible device means no push attempt.

## App contract and current blocker

Campaign push payload data is `{ "screen": "home|catalog|cart|orders|notifications|profile", "notification_id": "notification UUID" }`.
At send time the server adds `notification_id` to every push (campaign or
operational). It is the same UUID returned as `data[].id` by the notifications
API, not the internal integer ID. Server generation overrides any conflicting
custom payload field without replacing `screen` or `order_id`. Existing stored
notification data need no backfill. Already-sent pushes remain unchanged.
No arbitrary URL/path is accepted. The app must map this allowlist to its own
routes, continue supporting existing positive-integer `order_id` notifications,
and handle both live taps and cold-start responses after navigation/auth are ready.
Order APIs must enforce customer ownership. Never navigate to payload-supplied URLs.

Inspection of the local HeroKid-Mobile basic release found
`releaseFeatures.notifications: false`, a basic iOS plugin stripping
`aps-environment`, and a tap handler supporting only `order_id`. Backend readiness
does not enable push on that installed build. A separately reviewed mobile
release must enable registration/settings, APNs entitlement and valid Apple/Expo
credentials and the allowlisted navigation contract. Do not claim iPhone
delivery or destination support until tested on an authorized physical device.

## Mobile-agent handoff

- Register authenticated installations using existing `POST /api/v1/devices`:
  installation_id UUID, ios/android platform, locale ar/en, Expo push_token and
  explicit operational_notifications/marketing_notifications preferences. Never
  enable marketing silently. Existing PATCH/DELETE device routes update consent
  or revoke the installation on logout.
- Read existing `GET /api/v1/notifications`; mark a customer-owned notification
  read through its existing read endpoint. On a push tap, read `data.notification_id`
  and POST `/api/v1/notifications/{notification_id}/read` with the current user's
  Bearer token, then refresh unread state. The endpoint is idempotent and checks
  ownership; a UUID is not authorization. Wait for account/auth restoration on
  cold start. Never mark all notifications read on a single tap. Older pushes
  may lack the field: preserve navigation without guessing an ID. A read-request
  failure must not prevent navigation or falsely mark the item read locally.
  Campaign navigation contains only the allowlisted `screen` above; no arbitrary
  path or external URL.
- Suggested Expo routes: home → /(tabs), catalog → /(tabs)/catalog, cart →
  /(tabs)/cart, orders → /(tabs)/orders, notifications → /notifications,
  profile → /(tabs)/profile. Verify against the mobile release's actual routes.
- Support existing order_id payloads separately with a positive integer guard,
  normal authentication/ownership checks, and cold-start + foreground taps.
- Enable releaseFeatures.notifications and APNs in the production build (not
  local unsigned signing profiles); configure the correct Expo project and Apple
  credentials. No mobile files were changed in this backend-only release.
- If Expo enhanced push security is enabled, configure EXPO_PUSH_ACCESS_TOKEN
  privately in the server environment; it is used for sends and receipts and
  never exposed to browsers or mobile payloads.

Provider ticket vs receipt semantics follow:
https://docs.expo.dev/push-notifications/sending-notifications/

## Deployment

`scripts/deploy-main.sh FULL_COMMIT` now permits only the reviewed additive
2026_10_15_000100 campaign migration, still rejecting other schema/dependency
changes. It backs up code/.env/database before code deployment/migration,
keeps maintenance mode during migration and cache rebuild, and restarts workers.
No data backfill or customer sends occur. Keep the existing default database
queue worker scheduled. Failed jobs should be reviewed before manually retrying.

Verify admin navigation and direct access with view/manage roles after deploy.
Use one explicitly authorized test user/device to check real push, first with a
single-customer campaign. Check the result on the phone (foreground/background/
cold start) and destination; never use All for a smoke test. Until then only the
isolated mocked-provider checks prove backend behavior, not production delivery.

## Verification for this release

79 focused regression tests / 623 assertions passed using isolated SQLite and
mocked HTTP/queues, including permissions, explicit confirmation, idempotency,
single/all eligible recipients, opt-out/revocation, transient retries, provider
tickets, later receipts and token rotation. PHP 8.2 syntax, formatter, production
asset build, route/view caches and scheduler registration passed. Browser QA
confirmed customer search → preview → confirmation → queued result on a local
synthetic account. The isolated queue was not processed against a real provider;
zero live push attempts were made. Production and physical-device delivery remain
post-deployment checks, after the mobile agent enables the app integration.
