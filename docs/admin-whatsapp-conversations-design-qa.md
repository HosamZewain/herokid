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

## Historical conversation / reply-window repair — 2026-10-09

- Temporary localhost Fetch-response mock reproduced an empty cache followed by HTTP 502, without touching server settings or calling RoboDesk. The composer now says that reply eligibility cannot be checked because refresh failed, not that the WhatsApp window expired. [Actual local failure-state screenshot](qa/conversations/history-sync-failed.png).
- Removed the mock and reloaded the user's local preview afterward; the normal synthetic history and disabled-integration notice returned. No viewport override, production mutation or live send.
- Full Laravel/MySQL suite: 1285 passed / 10324 assertions; Node state tests: 12 passed. The backend regression includes an old cancelled order plus recent customer messages and an accepted mocked reply. This is a diagnostic/compatibility repair, not a visual redesign.

Hotfix final result: passed locally; live confirmation pending deployment.

## Active-contact awaiting-reply notifications — 2026-10-09

- `view-all` users now receive notifications for all active contacts, including unassigned orders and other employees' orders; own-only employees remain restricted to their assigned active checkouts. Historical manual viewing is unchanged.
- The badge counts contacts awaiting a human response, not unread contacts. Reading remains per employee and is shown independently; opening a conversation leaves it in the list until a successful human response. A later customer message restores it. Bots, automatic integration messages, reactions and failed/uncertain sends do not dismiss it.
- Accepted local sends refresh the badge immediately; synced RoboDesk employee responses are reflected on the next stored-state refresh. No real-customer message was sent.
- Local 628px viewport check: two already-read pending synthetic contacts stayed visible in the dropdown. Its position is clamped to the viewport (296–616px inside a 628px viewport), correcting a pre-existing right-edge clipping issue. [Local synthetic notification screenshot](qa/conversations/awaiting-reply-notifications.png).
- Focused conversation/history/background suites: 84 passed / 553 assertions. Full `composer test`: **1299 passed / 10364 assertions** (294.11s). Node state helpers: 12 passed. Build, manifest assets/imports, changed PHP formatting, PHP 8.2 syntax checks for all changed PHP files and Blade compilation passed. Full regression runtime uses PHP 8.5 / MySQL 8.4; no new schema or hosting configuration is needed.
- Run tests with isolated `APP_ENV=testing`, `APP_URL=http://localhost`, array cache/session and sync queue environment values. The initial run inherited the preview's `localhost:8089` URL and failed one unrelated hard-coded prompt URL assertion; the complete rerun with the correct test URL passed without changing production-prompt code or loosening assertions.

## Current assignee and WhatsApp Business replies — 2026-10-09

- Read-only observation of the existing production polling response for HK10-110 confirmed a later outbound text with `sender_type=whatsapp_business_app` and informational `pending` delivery status. The previous human-response predicate excluded that sender; this was not a stale-sync issue. No message was sent and no customer data or protected media was copied into fixtures.
- With explicit user approval, the human-response predicate now recognizes that exact Business-app sender in addition to RoboDesk employees and locally recorded employee replies. Bot/AI/unknown/automatic-integration messages, reactions, earlier replies and known failed deliveries remain excluded. A new customer message after a Business-app reply restores the notification.
- History and notifications show the current linked order's assigned employee underneath the customer name, with `غير معيّن` for unassigned orders. Tests cover assignment/reassignment/release, shared-phone representative orders and own-only access. Reads never change assignments or contact history.
- Reloaded the built localhost page and verified names in both the notification list and conversation header at the existing narrow viewport. Long header names are ellipsized with the complete name in the title. [Synthetic local screenshot](qa/conversations/assigned-employee-notifications.png).

### Inline audio controls

- Added native audio controls for voice/audio messages and recognized legacy audio filenames, including `.ogg`. No autoplay, lazy loading, an HTTPS fallback link and a missing/failed-source notice.
- 21 JS helper tests passed, including DOM reconciliation, rotating URLs, paused seek restoration, source revocation, minimizing/closing, control construction and error recovery. The four relevant Laravel test classes passed: 100 tests / 608 assertions. Production build and manifest dependency validation passed.
- Local synthetic history responses verified the actual controls at narrow and desktop widths, with `preload="none"` and no autoplay. Interception did not provide a playable native media response, so actual codec playback was not confirmed; these fixtures are not evidence of real RoboDesk OGG playback. Test interception was cleared and the temporary synthetic Chrome login removed. No production messages were sent or changed.
- HK09-461 read-only diagnostics returned matching API/saved counts of two messages despite a limit of 50 conversations: inbound customer text followed by outbound `bot` text on 2026-09-26. WhatsApp follow-up messages shown by the user were absent from the full provider response. Pending-reply semantics were not changed to hide this upstream discrepancy.

### Historical reply reconciliation

- After RoboDesk's Business-app import repair, HK09-821's saved history contained nine messages, including Business-app outbound replies on October 5 and 6 after the last saved inbound on September 26. The notifications query already excludes this replied conversation. Opening requests full history; reading alone does not dismiss pending conversations. The earlier missing-message/cursor situation can therefore clear on opening, without implying that mark-read dismissed an unanswered contact.
- Added a nullable `last_full_synced_at` column and hourly full recent-window reconciliation on the existing sync request. Legacy records reconcile on their next eligible refresh. Delta polling, queue limits, authorization, read watermarks and notification rules remain unchanged. No extra HTTP call, no full-history retrieval on notification GET, no new environment variables or scheduler/worker setup.
- Deployment requires `php artisan migrate --force` before traffic/workers use the new code, followed by normal configuration cache refresh and queue restart. The additive migration neither deletes messages nor changes assignments; keep the existing queue worker/cron.
- Regression tests cover read-unanswered retention, hidden backdated human replies, sender-metadata correction, later inbound reopening, migration-era first reconciliation, subsequent delta requests and failed reconciliation retry without lost history/cursors.
- Verification: focused tests **104 passed / 638 assertions**; full Composer suite **1314 passed / 10424 assertions**; JS tests **21 passed**; changed-file Pint, PHP 8.2 syntax and whitespace checks passed. The additive migration succeeded on the isolated local SQLite preview and the MySQL test database. [Synthetic local notification retention screenshot](qa/conversations/read-unanswered-retained.png) shows contacts still marked read/awaiting reply after opening and minimizing. No production deployment, customer reply or live scheduler verification is claimed.
- No new migrations, credentials, environment variables or scheduler changes. PHP 8.2 syntax, changed-file Pint, Blade compilation, Vite build, manifest asset/import checks and all 12 Node state-helper tests passed. The first complete Composer run hit its 300-second process limit; a subsequent shared-test-database run encountered migration setup errors. Final full verification uses a dedicated test database with a 600-second process allowance; no application assertions were weakened.
- Final complete `composer test`: **1310 passed / 10394 assertions** (268.28s), using isolated `testing_conversations_oct9` on MySQL 8.4 / PHP 8.5. The main frontend bundle differs only in generated conversation asset import filenames. Production deployment remains unperformed.
