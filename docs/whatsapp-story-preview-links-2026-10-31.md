# Story scenes WhatsApp links — 31 October 2026

Status: prepared, not deployed. Deployment approval is still required for this change.
Production SHA verified read-only: `004be8a84d09597ce037ce3ce4e6d0ee7f742ed0`.
Branch: `codex/whatsapp-all-story-preview-links`.

## Correction

`OrderWhatsAppMessageService` selected only the first active booklet preview in a checkout. `{{preview_scenes_url}}` now joins every available active current-preview scenes URL in that checkout with a newline, retaining group order and removing duplicate/empty URLs. Existing grouping excludes deleted orders and unrelated checkouts; revoked/deleted previews and previews without a current version remain excluded. Current preview links stay stable across PDF versions; obsolete PDF versions are not separate customer links.

Templates containing this variable also count as explicitly specifying a preview, so the default preview template no longer appends an extra first-story reader link. Other preview variables retain their existing behavior. The existing saved Arabic template requires no edit.

## Verification and release scope

Regression fixtures cover multiple stories from either representative, exact multiline message and WhatsApp URL encoding, duplicate links, single/empty results, revoked/missing-version/invalid-token/deleted previews, deleted orders and another checkout. Related booklet reader and product preview tests are included. All data is synthetic in isolated Docker databases with external networking blocked; no customer message, production database mutation or media access was performed.

Validation: **26 tests / 232 assertions passed on each SQLite and isolated MySQL**, running PHP 8.5. PHP 8.3 syntax checks and Pint pass. No frontend change, dependency, schema migration, configuration change, queue refresh or new infrastructure is needed. Deploy only after approval using the narrow AWS runbook procedure; preserve live configuration/data and verify the service hash and rendered message without sending it. Rollback is code-only to the verified prior release, with no database restore.
