# AWS migration branch validation

Base: `origin/main` at `b8c84ccc593c1a68f6d88e35f96f8d1613a8c013`.
Branch: `codex/aws-final-production-migration`.

No Hostinger/AWS deployment or production data changes were performed. No main commit is included. AWS staging verification remains an operator step using [the staging runbook](aws-final-migration-staging.md). The S3 Media Library delete issue is explicitly outside this change.

## Scope and files

Application changes:

- `.env.example`: document explicit library, Studio and expense storage settings.
- `config/filesystems.php`: configurable `studio_private`, keeping the Hostinger root and AWS `private/studio-private` prefix.
- `config/media.php`: independent expenses disk, default local.
- `app/Services/Storage/PersistentMediaReferenceRegistry.php`: register both library paths; exclude expenses from S3 verification/migration.
- `app/Services/RemoteStudio/StudioSettingsService.php`: stream-based SHA-256 integrity checks.
- `app/Services/Expenses/ExpenseLedgerService.php`: new/replacement attachments and cleanup use the expenses disk.
- `app/Http/Controllers/Admin/ExpenseController.php`: legacy receipt reads/downloads use the expenses disk; existing attachment records keep their own disk.

Tests and documentation:

- `tests/Feature/AwsMigrationStorageTest.php`
- `tests/Feature/AdminExpenseEnhancementsTest.php` (update the previous S3-invoice expectation to the requested local behavior).
- `docs/aws-hybrid-media-storage.md`
- `docs/aws-final-migration-staging.md`
- `docs/aws-final-migration-validation.md`

No schema, dependency, frontend asset, order/payment behavior, authorization, or logical media-path changes.

## Test environment

PHP 8.5.5, PHPUnit 11.5.55, isolated SQLite `:memory:`. PHP dependencies from the unchanged lockfile. The normal/full suite ran in Docker with network disabled, explicit `APP_ENV=testing`, `APP_URL=https://hero-kid.com` (URL generation only; no site requests), a synthetic test APP_KEY, empty DB_URL, and array/sync test cache/queue. No production `.env` or database was used.

The S3 run used a disposable MinIO container and the real AWS SDK/Flysystem S3 and path-prefixing adapters. It used only emulator credentials and a test-only bucket/UUID root, not an AWS account. The emulator and its synthetic objects were removed afterwards.

## Results

- Focused storage/regression run: **105 tests, 817 assertions, all passed**. Includes new storage tests, RemoteStudio API and cover tests, PublicStoryLibrary, AdminExpenses, HybridMediaStorage, and the two updated local-invoice regression tests.
- Actual S3-adapter/MinIO run: **8 tests, 106 assertions, all passed**. Includes verified library reference migration and missing-cover refusal, public PDF Range/body/cleanup and covers, new library uploads, Studio settings integrity/publication/protected downloads, local fallback, cover generation with mocked AI/reference cleanup/output download/retention, and expense exclusion/local reads/uploads.
- Final full suite: **1,692 tests, 15,909 assertions; 1,685 passed, 6 failed, 1 skipped, 0 errors** (289 seconds). All six failures reproduced on the unchanged base as listed below. No new failing test was introduced in this environment.
- Pint on all eight changed PHP files: **passed**.
- Full repository Pint: **20 pre-existing files flagged**, none modified by this branch. No unrelated formatting sweep performed.
- `composer validate --strict`: **passed**.
- `git diff --check`: **passed**.

### Existing full-suite failures reproduced on the unchanged base

The following six cases also fail at the base SHA under the same PHP/SQLite/APP_URL environment:

1. `AdminExpenseEnhancementsTest::test_inline_category_update_preserves_financial_values_and_all_attachments` — income case: raw numeric `123.45` versus cast string `'123.45'` assertion.
2. Same test — expense case.
3. `AdminOrderProductionPromptTest::test_child_image_variable_renders_numbered_secure_urls` — expected localhost URL versus configured public URL.
4. `AdminPermissionsTest::test_dashboard_statistics_require_the_separate_owner_permission` — expected dashboard wording `المتبقي تحصيله` absent.
5. `MobileProductPersonalizationTest::test_two_children_create_separate_production_orders_with_exact_fields_and_ordered_private_photos` — Agent middleware receives a false abilities value from the test auth flow.
6. `OrderWhatsAppConversationTest::test_saved_history_paginates_and_foreign_cursor_is_rejected` — expects 15 messages, receives 0.

The MySQL-native dump roundtrip test is skipped on SQLite. These findings are not a claim of a green full-suite production release gate. They were kept out of this focused storage change.

### Reproduction

In an isolated testing checkout with no production `.env` or cached config, run the full suite with the explicit test environment above:

```bash
php vendor/bin/phpunit
php vendor/bin/phpunit --filter='AwsMigrationStorageTest|RemoteStudioApiTest|RemoteStudioCoverTest|PublicStoryLibraryTest|AdminExpensesTest|HybridMediaStorageTest|test_multiple_private_invoices_are_appended_and_legacy_attachment_is_preserved|test_multiple_attachments_are_optional_and_hostinger_default_is_local'
php vendor/bin/pint --test
composer validate --strict
```

For real S3 adapter coverage, use `HEROKID_TEST_MINIO=1` and the isolated emulator setup documented in the staging runbook. Do not run the test suite against staging/production databases.
