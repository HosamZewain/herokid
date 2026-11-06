# Sales assistant conversation and responsive-page correction — 7 November 2026

## Scope

Based on the owner's observed real conversation and explicit instruction to apply corrections, enlarge the message font, remove the visible privacy section, verify mobile, and remove the boxed treatment from the full page. Continues the requested immediate AWS publication of the sales assistant. Release base revalidated as AWS and GitHub main `a997ee3f4c97e6b0e2d85c615b4039f95b055ac9`.

- Derive age/gender from retained, redacted messages in the same conversation, including messages older than the last four model inputs. A stated new child clears stale age; ambiguous siblings require a choice without a provider call. Generic pronouns/topic changes do not switch the child.
- Enforce the declared child facts on seed search, MCP search/direct reads, manual catalog fallback and final cards. Ignore generic discovery words when searching for a specific topic; do not substitute unrelated stories when there is no match. Replace conflicting prose or repeated age questions with a safe summary.
- Preserve ordinary assistant introductions and explicit public story-title references. Still redact private names, contacts, URLs and addresses, including names equal to catalog titles. Remove redaction markers from displayed replies without rewriting stored conversations, provider receipts or usage.
- Increase messages to17px, composer16px and card details14px. Full-page conversation uses the page surface, with no outer border, shadow or rounded box. Keep a bounded reading column and composer in the available viewport, responding to storefront-header and visual-viewport changes. Keep the drawer separate; hide the welcome panel once a conversation exists and scroll new replies into view.
- Remove the requested visible privacy disclosure. Encryption, retention, redaction, `store:false`, model, budget, session ownership, CSRF, tool permissions, order creation and usage reporting remain in force. The dedicated page omits the ordinary storefront footer/floating WhatsApp to keep its composer available; its explicit team handoff remains.

## Validation

- PHP8.3 SQLite and isolated MySQL8.4: **40 tests /342 assertions EACH**, covering assistant/checkout/permissions and12 new conversation-quality regressions. No external requests permitted; synthetic databases and credentials only.
- JavaScript5 tests, PHP8.3 syntax/Pint for8 changed/new PHP files, JS syntax and diff whitespace checks pass.
- Lockfile build passes. All71 pre-release tracked build assets preserved byte-for-byte;19 manifest entries resolve. No dependency/lockfile change.
- Local Chromium visual/interaction checks:1440×1000 desktop,390×844 mobile,320×568 narrow mobile,844×390 landscape and390×460 reduced-height keyboard simulation. No horizontal page overflow; full page has no border/radius and spans the viewport;17/16/14px computed fonts; composer visible. Same conversation survives drawer→full-page navigation. Message202/queue completion, choice/native child-data form, Escape and WhatsApp separation pass; zero browser JavaScript errors. Screenshots inspected. This is emulation, not a physical iOS/Android keyboard test.
- Provider replies in local tests are synthetic. Owner's existing real conversation diagnosed the defect; no new paid production test, customer message or order is created. Real GPT ranking after the correction remains to be observed in genuine traffic.

## Release and recovery

Prepared in isolated `codex/sales-assistant-relevance-20261107`; primary dirty checkout preserved. No schema, configuration, dependencies, route definition or infrastructure change. Publish only the reviewed source/assets; preserve production private config/environment, all historical assets and existing customer records. Gracefully stop/start only the assistant worker, allowing an in-flight job to finish; no queue clear/replay, shared-cache flush or unrelated worker signal. Keep enabled state,USD5/day, exactGPT-6Luna, assigned-only visibility and existing AutoPilot configuration.

Record actual deployed SHA, checkpoint, native/public checks and bounded logs in the AWS runbook only after successful publication. Forward repair preferred; compatible code-only recovery preserves all newer messages, receipts, reservations, orders and media.

## Deployment evidence

Deployed application `2c2327bc97d3676fb467978e6f5dd0aaf430cf62` at6November23:57:52–58UTC. Recovery `/home/ubuntu/herokid-before-sales-quality-20261107`; sanitized evidence `/home/ubuntu/herokid-sales-quality-release-20261107`. All16hashes/74prior assets/private config/environment/routes preserved; four completed historical turn records unchanged. Scoped assistant refresh, Blade cache and FPM reload only. Public HTTPS/current assets200; native owner usage report200 and restricted actors403; queue0/heartbeat3s, failedjobs86unchanged, nine services active and scheduler success. Initial bounded logs show no new app/worker/vhost errors or5xx. No synthetic production inference/order/message. See AWS runbook for exact recovery boundaries; documentation successor does not change application behavior.
