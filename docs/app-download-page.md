# Public app landing page

`GET /app` (`app.download`) is a public, indexable landing page with app features,
actual native screenshots and download links. Listed in the sitemap, footer and
HeroKid guide menu. No database migration, account or mobile-app change required.

## Stores

- iOS: https://apps.apple.com/eg/app/herokid/id6821424235
- Android: https://play.google.com/store/apps/details?id=com.hero_kid.app

Public URLs verified on 2026-10-17 (both HTTP 200). Apple lookup independently
matched track ID 6821424235, bundle com.herokid.app and developer Hosam Zewain.
Links live in `config/mobile_app.php`. Do not use the similarly named unrelated
HeroKid story app (6758614616).

The owner requested reading as the primary benefit: purchased child-story PDFs
and the separate public full-story library, with shopping secondary. Purchased
stories require the owner's account, a delivered order and an available document;
payment alone does not unlock a PDF. Existing approval/production flows unchanged.
The owner confirmed immediate feature availability and requested no upcoming-update
label; the page presents both reading benefits as core app features and also links
to the website library. PDF readiness/account ownership remain explicit.
On 2026-10-17 Apple public lookup still returned 1.0.0 without digital reading;
the local mobile release record reported 1.0.1(12) awaiting review. Production
`/api/v1/library/books` returned 404, while the public story-library API returned
one story. Publishing/enabling purchased-library backend/app support is separate
work and is not silently bundled into this marketing-content change.

## Device detection and performance

HTML always includes both working first-party download links, even without
JavaScript; each records an eligible click then redirects to the fixed HTTPS store.
Client-only detection highlights and orders the appropriate store; there is no
automatic redirect. Recognizes Android, iPhone/iPad/iPod, plus iPad desktop mode
using MacIntel + multiple touch points. Desktop/unknown devices show both equally.
User-agent-specific HTML is deliberately avoided to prevent CDN cache mixing.
Detection is a convenience, not an access/security check; the other link remains.

Only this page loads its dedicated CSS (~8.6 KB) and detection/measurement JS
(~1.7 KB). Actual
Arabic native screens from the mobile project's public store submission assets
are resized to 600x1304 WebP, without semantic changes: home 84,990 bytes, catalog
59,154 bytes, account 30,680 bytes (174,824 total). Existing screenshot content is
guest-only with no customer details. Gallery/account images are lazy-loaded; the
hero's main screen has high fetch priority. No third-party image or QR service.

## Verification

- Feature tests: public access, canonical/sitemap/navigation, both download URLs,
  same cache-safe behavior for different user agents and optimized local images.
- JS tests: Android/iOS/iPad desktop detection, Windows/Mac/unknown fallback,
  matching-store recommendation in both CTA groups and resetting previous state.
- Browser QA: desktop, 390px phone and 834px tablet layouts; actual user-agent
  emulation for iPhone/Android/iPad desktop mode; no document horizontal overflow.
  Mobile gallery scrolls separately, without forcing whole-page horizontal scroll.
- Production build and route/view cache checks on isolated SQLite QA only.
- Run pinned `scripts/deploy-main.sh` from main, then verify `/app`, screenshot and
  built-asset URLs return 200 and check the store buttons on real iOS/Android.
  Backend/app installs and store publication are not changed by this release.

Analytics QA on 2026-10-17: 36 focused SQLite feature tests / 340 assertions;
13 recorder/report tests / 134 assertions repeated on disposable MariaDB 10.11;
9 JS/deployment tests passed. PHP 8.2 lint, Pint, Vite build and route/view caches
passed. Report service query count is asserted at 3 (summary, daily, first date).
Browser guest navigation reached both real stores and the isolated admin report
showed 1 unique visitor, 2 views, 1 iOS clicker and 1 Android clicker; no production
tracking events were created. Report date filtering and 390px layout checked.

## First-party page report

`/admin/app-download-analytics`, Reports → App page statistics, gated by existing
`analytics.view`. Does not require GA4 configuration. Daily summaries and selected
period totals, default seven days; today/yesterday/30/custom (maximum366 days).
Dates use Africa/Cairo including DST; event time and date are server assigned.
Unique visitors are distinct browser hashes across all eligible page interactions
in the period. Views count document loads; iOS/Android clicker cards count distinct
visitors per store. Click totals are separately labeled. One visitor clicking both
stores appears in both store cards but only once in overall click-through rate.
Period uniques must not be obtained by summing daily uniques.

One additive `app_landing_events` table, indexed by Cairo date/type and date/visitor.
No new permissions, foreign keys, dependencies or order/payment mutations. A random
web-session-derived visitor ID persists in an encrypted HttpOnly same-site cookie
for90 days; only its HMAC hash is stored. No raw IP, full UA, account ID, customer
data, query strings or referrer is stored in the events table. There is no automatic
deletion of historical event rows. The privacy page explains this measurement.

Visits use a separate no-store same-origin GET endpoint with a custom request
header and a required page UUID, after the document becomes visible. This avoids
cached-page CSRF tokens and does not weaken account/cart CSRF protection. Store
links use server302 redirects so a failed beacon/network request never blocks the
download. Fixed allowlisted targets only; event/page/visitor IDs are not forwarded
to the stores. Eligible clicks also ensure a view exists, with unique dedup keys;
both buttons for one store in one document count once. Without JS, clicks use a
10-second duplicate-tap bucket and record a view; passive no-JS views are not counted.

Same-site /app referrer required. Admin sessions, known bot/headless/preview UAs,
HEAD, prefetch/prerender, DNT/GPC opt-outs and cross-site requests are ignored.
Writes are rate-limited (120/minute); download redirects still work if throttled
or storage fails. Such exclusions, cookie blockers and device changes make these
browser-based measurements approximate, not certified person counts or install
attribution. No historical counts are invented; collection starts after deployment.
Bot filtering is best effort, not proof that all automated traffic is excluded.

The deployment script backs up first and applies only the reviewed additive app
metrics migration, alongside the two previously reviewed migrations. Shared-cache
HTML remains device/visitor-neutral; visit responses and store redirects are
no-store. Verify the report with an authorized admin, then a real guest interaction;
do not publish synthetic QA events into production.
