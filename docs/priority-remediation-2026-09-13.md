# Priority remediation — 2026-09-13

Scope: P1 findings from the follow-up audit, preserving existing successful purchase, upload, pricing, stock, address-form and Agent API contracts. No production customer records were edited.

## Implemented

- **P1-1:** Admin full-edit rollback tracks exact paths reported by its photo upload operations (story and personalized-product paths). It no longer inventories the shared photos directory or deletes other requests' files. Existing photos remain untouched.
- **P1-3:** Validate every child's fields and photo ownership before attaching any upload. Commit the attachment batch atomically, with locked/current-state upload validation. A later invalid child or duplicate upload rolls the entire attachment batch back, permitting a corrected retry. Existing reuse-first behavior remains unchanged.
- **P1-4:** Customer and Admin full address edits invalidate old Bosta city/district/zone identifiers and labels when destination fields change. Contact/street/detail-only edits retain valid mappings. Shipping then uses the existing resolver against the updated governorate and district text, including its existing rejection of unmatched/incomplete destinations. Saving does not call Bosta or create shipments. Other delivery/payment metadata is preserved; existing stored orders are not bulk-rewritten.
- **P1-5:** A hidden cart submission token, server-side HMAC bound to the session and exact cart, database primary-key claim and purchase transaction deduplicate repeated submissions. The checkout route uses Laravel session blocking. Completed form replays redirect to the existing success result without duplicating purchase side effects or clearing a newer cart. Legacy forms without the hidden field still use the server-derived key. New cart item identities allow intentional repeat purchases. Failed transactions roll back the claim. New migration: `2026_09_13_130000_create_checkout_submissions_table.php`.
- **P1-6:** `public/.user.ini` requests 64M per upload / 80M POST. Application limits are unchanged: customer photos 15 MB, Admin attachments 50 MB. This is not confirmation of effective Hostinger settings. Synthetic Laravel validation tests cover valid 25/50 MB PDFs, oversize files and executable rejection; they do not simulate an actual 25 MB network transfer.

## P1-2 remains open — approval required

Tracking POST now has the same 30/minute defensive throttle used by phone lookup. **This does not fix the ownership authorization gap.** Phone-only lookup still exposes usable order references, and reference-plus-phone remains the current tracking login.

Closing the gap requires changing customer access behavior: an ownership-verified flow such as a purpose-bound signed tracking link or OTP, including previous-order merge/cancel authorization. The user explicitly required no behavior changes, so approval was requested before replacing that workflow. No new link delivery, OTP provider, account requirement or silent access restriction was introduced. Do not mark P1-2 or all P1s resolved.

## Release verification and operations

Final local checks passed: **872 Laravel tests / 7,059 assertions**, **17 browser tests** (including the localhost synthetic HEIC upload), Laravel Pint, `npm run build`, and `git diff --check`. Eleven regression tests were added across `PriorityRemediationTest` and `AdminOrderFullEditTest`. Checkout coverage includes cleared-cart replay, preservation of a newer cart, and intentional purchase after AJAX addition/removal with the refreshed hidden token. The replay/rollback tests are synthetic; an actual concurrent production checkout was not performed. No production deployment or live customer/shipment/attachment mutation is implied by local tests.

Deploy PHP/Blade and run `php artisan migrate --force` while in maintenance mode, then clear/rebuild configuration, route and view caches and restart workers. Retain the existing shared cache/session configuration; Laravel session blocking needs an atomic-lock-capable cache store. The database uniqueness boundary provides independent purchase replay protection. No npm command is needed on Hostinger when deploying the committed build.

Verify web-SAPI upload limits in hPanel (not CLI `php -i`), allowing for `.user.ini` caching/server overrides. Use an authorized synthetic order to test a PDF larger than 20 MB and remove the test attachment afterward through the normal application workflow. No production upload-limit verification has been performed here.

Submission records contain only keyed hashes, order IDs and timestamps, not raw session IDs or customer text. They have no automatic pruning; deleting them removes historical replay protection. No destructive cleanup or production backfill is part of this release.
