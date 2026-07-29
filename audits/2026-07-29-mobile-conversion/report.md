# HeroKid mobile conversion audit — 2026-07-29

Viewport: 390 × 844. Source journey: live public HeroKid storefront, story page, cart, pricing, homepage, FAQ and how-it-works pages.

## Evidence

- `screenshots/01-shop-mobile-before.png`: the mobile store introduction and three category cards push the catalog below the first screen.
- `screenshots/02-story-mobile-before.png`: the story page has no persistent purchase action or visible four-step order explanation.

## Verified findings and resolution

| Priority | Verified issue | Resolution in `codex/mobile-conversion-flow` |
| --- | --- | --- |
| P0 | `/stories` showed the unified-store hero before story results. | The backward-compatible `/stories` alias now starts at the filtered catalog. The category introduction is collapsed below results on mobile `/shop`. |
| P0 | Story CTA was not persistently reachable on mobile. | Added a fixed price/story CTA bar that scrolls to customization, plus a compact sticky story/price/cart header inside the form. |
| P0 | The full journey was not explained before child information. | Added one reusable four-step progress component on the story, cart and success pages. |
| P0 | Public copy conflicted on price, language and photo count. | Story price remains driven by the existing pricing service. Ready products are explicitly distinguished. Story photos are consistently 2–3. Language messaging is derived from the active catalog. Outdated FAQ copy is normalized by migration. |
| P0 | Mobile checkout total appeared below the address form. | Product price, delivery fee and total now appear before delivery fields on mobile. |
| P0 | Final checkout wording implied immediate confirmation/payment. | Changed to “إرسال الطلب للمراجعة — بدون دفع الآن” and moved the WhatsApp confirmation/payment explanation above the form. |
| P1 | Story customization was one long form. | Split into child details, photos, optional dedication, and review/add-to-cart stages. |
| P1 | Optional fields were always expanded. | Optional dedication and notes are collapsed by default. |
| P1 | Age was an unrestricted number. | Age is now selected from the Story’s configured age range and server-validated against it. |
| P1 | Disabled add-to-cart had no explanation. | Added a live, accessible missing-requirements list. |
| P1 | Checkout fields lacked autofill and mobile input hints. | Added associated labels, IDs, autocomplete values, `type="tel"` and `inputmode="tel"`. |
| P1 | Back navigation could lose form state. | Text/select drafts persist in session storage; successful upload IDs retain the existing local-storage resume behavior. |
| P2 | English country name and Western age ranges appeared in Arabic UI. | Egypt is normalized to “مصر” for new selections and age labels use Arabic digits. Historical order snapshots remain unchanged. |
| P2 | Delete controls were below the 44 px target. | Story-photo and cart removal actions now have a minimum 44 px height. |
| P2 | Long mobile story copy added friction. | Story description is collapsed behind “عن القصة” on mobile. |
| P2 | Focus/error behavior was weak. | Added global visible focus styling, invalid-field styling, live requirement announcements and first-invalid-field focus after server validation. |

## Verification note

The browser session was permitted to capture the live before-state only. Localhost access was blocked by the selected browser policy, so post-change visual verification was performed through compiled Blade rendering, responsive utility review, feature tests, production asset build, and cache compilation rather than a second browser screenshot.
