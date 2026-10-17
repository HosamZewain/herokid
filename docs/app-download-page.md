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
HeroKid story app (6758614616). Marketing copy describes current physical-commerce
features, not the pending iOS digital-library update.

## Device detection and performance

HTML always includes both working HTTPS store links, even without JavaScript.
Client-only detection highlights and orders the appropriate store; there is no
automatic redirect. Recognizes Android, iPhone/iPad/iPod, plus iPad desktop mode
using MacIntel + multiple touch points. Desktop/unknown devices show both equally.
User-agent-specific HTML is deliberately avoided to prevent CDN cache mixing.
Detection is a convenience, not an access/security check; the other link remains.

Only this page loads its dedicated CSS (~8.6 KB) and detection JS (<1 KB). Actual
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
