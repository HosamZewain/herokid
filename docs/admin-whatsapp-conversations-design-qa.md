# WhatsApp conversation dock — design QA

Final result: **passed** (local visual/interaction foundation; live credentials not supplied).

## Source and evidence

Source: `/Users/hosam/Desktop/Screenshot 2026-10-09 at 3.24.48 PM.png` (user's Messenger-style reference).

Rendered application: `http://localhost:8089/admin/orders?catalog_type=products&lifecycle=active`.

- Desktop, 1280×900, two expanded independent conversations: `/private/tmp/herokid-conversations-desktop.png`.
- Mobile, 390×844, one expanded conversation: `/private/tmp/herokid-conversations-mobile.png`.
- Credentials/settings screen: `/private/tmp/herokid-conversation-settings.png`.

Source and desktop implementation were opened together in the same comparison input. This is a reference-inspired admin feature, not a pixel clone: the source is a larger Messenger window with real personal photos and messaging controls; the implementation uses compact floating windows over HeroKid's existing admin and clearly labelled synthetic LOCAL fixtures.

## Fidelity surfaces

- White rounded panel/header, subtle shadow, customer heading, clear close/minimize controls.
- Purple outbound and gray inbound message bubbles; Arabic plain text wraps naturally with Cairo timestamps and day separators.
- Existing HeroKid Arabic typography and official bundled Heroicons; no fabricated customer avatars.
- Desktop windows start at the bottom left and grow rightwards, clear of the right admin sidebar; each window retains RTL content. Verified window left edges at 24px and 396px in a 1280px viewport. Mobile width and height fit the viewport without a full-page overlay (10–376px in a 390px viewport).
- Intentional differences: read-only footer replaces composer; no call/video/like controls, verification badge or invented reply quoting. Provider v1 does not supply those features. Integration-disabled notice is prominent while credentials are absent.

## Interaction verification

- Opened from order listing, displayed cached synthetic messages and disabled integration notice.
- Opened a second customer simultaneously; minimized/reopened windows independently.
- Opened another order with the same canonical phone; window count stayed at two instead of creating a third duplicate.
- Closed the last open window; DOM window count became zero.
- Keyboard focus goes to close on open, Escape/close restores trigger focus; non-modal regions do not trap unrelated admin interactions.
- Settings page rendered empty password input, activation toggle and 1–50 limit; no credentials entered during browser QA.
- Responsive viewport override reset after testing. Agent-created preview tab retained as a deliverable.

No unresolved P0/P1/P2 visual deviations from the agreed read-only scope. No live provider claim: real auth, signed media expiry and incoming real-message polling still require credentials entered by the administrator after deployment.

## Automated verification

- Full Laravel/MySQL suite: 1238 passed, 9950 assertions.
- Focused conversations + existing RoboDesk integration: 29 passed, 169 assertions.
- The same focused suite on strict MariaDB 10.11: 29 passed, 169 assertions.
- Node state/URL/polling policy: 4 passed.
- Changed PHP Pint: passed; PHP 8.2 syntax checks passed; Vite build and git diff whitespace check passed.

## Persistence, replies and employee notifications — 2026-10-09

The preceding sections describe the initial read-only release. This follow-up adds the requested composer and notification behavior without changing the public storefront.

- Bubble reference: `/Users/hosam/Desktop/Screenshot 2026-10-09 at 6.52.04 PM.png`. Source and rendered desktop evidence were inspected together. Intentional differences: retain HeroKid's light admin panels and generic bundled user icons because RoboDesk does not provide customer avatars; use the reference's compact left-hand circular stack, close affordance and name tooltip.
- Saved actual viewport captures: [desktop conversation and notifications](qa/conversations/persistent-desktop.jpg), [mobile composer](qa/conversations/persistent-mobile.jpg), [minimized bubbles](qa/conversations/minimized-bubbles.jpg). Desktop is 1440×960; mobile is 390×844. Synthetic fixtures only; no customer-private content or live credentials.
- Verified refresh, admin-page navigation and a fresh browser tab preserve expanded/minimized windows. Closing a bubble persists the removal. Storage contains only user-scoped order IDs and minimized flags, not names, messages, credentials or signed URLs.
- Clicking the header minimizes; the visible minimize word is removed. The separate close button and linked order reference retain independent behavior. Names use eight graphemes and a full-name tooltip. Opening another order for the same canonical phone reuses its conversation window and updates the order link.
- Notification count deduplicates three assigned active orders into two customer conversations. Opening and loading a notification marks it read for that employee and reduces the count; merely loading the notification list does not. All-customer access and replying have separate permissions; direct API actions recheck current assignment.
- Composer supports text, caret-position emoji insertion, image preview/removal and an explicit send action. A synthetic image was selected and removed successfully. The local integration remains disabled, so no real message was sent. Mocked provider tests cover accepted sends, refusals, uncertain outcomes, idempotency and the 24-hour reply window.
- Responsive checks at 320×640, 390×844 and 1440×960 showed no horizontal overflow. An initially clipped mobile send button with the emoji picker open was fixed by preserving header/toolbar space and making the composer scrollable; final send-button bottom was 615px inside a panel ending at 630px at 320×640. Browser console errors/warnings: none in the final inspected states.
- Full Laravel/MySQL suites both passed: `composer test` and `php artisan test --compact`, each **1281 tests / 10252 assertions**. Node state tests: **11 passed**. PHP 8.2 syntax checks, Blade compilation, production asset build and whitespace checks passed. Full runtime regression tests used PHP 8.5, not PHP 8.2.
- Background refresh is bounded and queued for active orders only, with a minimum ten-minute interval per contact. Header polling reads local stored state; it does not call RoboDesk. Deployment requires the new migration, employee permission selection, Laravel scheduler and the `robodesk-conversations` queue worker. No new environment variables, production deployment or live customer sends occurred.

Follow-up final result: **passed locally**. Production credentials, queue/scheduler operation and real WhatsApp acceptance remain deployment checks, not claims from this local QA.
