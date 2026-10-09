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
