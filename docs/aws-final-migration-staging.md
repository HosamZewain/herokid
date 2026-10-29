# Final AWS storage migration: staging validation

This branch is preparation only. Do not run the Hostinger deployment script, modify main, or deploy production as part of this procedure. The existing S3 Media Library delete issue remains deferred.

## Storage contract

| Domain | Laravel disk | AWS location / EC2 root |
| --- | --- | --- |
| Private order/customer/production | `s3_private` | `private/{logical_path}` |
| Public Story Library | row's disk; new uploads `STORY_LIBRARY_DISK` | `private/story-library/...`, served through Laravel |
| Remote Studio | `studio_private` | `private/studio-private/settings/...`, `private/studio-private/covers/...` |
| Expenses, old and new | `local` via `EXPENSES_MEDIA_DISK` / existing attachment record | `storage/app/private/expenses/...` |
| Public catalog | `public` | `storage/app/public` |
| Processing and temporary media | `local` | `storage/app/private` plus existing PHP/framework temp directories |

The eventual production bucket is `herokid-prod-media-2026`, region `eu-central-1`. **Staging must use a separate bucket, a separate DB clone and local copied fixtures, never production resources.** Restrict the staging instance role to that staging bucket only. Keep public access blocked. Use IAM `ListBucket` scoped to required prefixes and object read/write/delete permissions as needed; add KMS permissions only if the bucket's encryption requires them. Do not set static AWS access keys, secret keys, session tokens, or a production credentials profile. Leave `AWS_ENDPOINT`/`AWS_URL` unset for AWS; EC2's default credential chain uses its instance role.

## Environment

```dotenv
FILESYSTEM_DISK=local
PRIVATE_MEDIA_DISK=s3_private
PUBLIC_MEDIA_DISK=public
PROCESSING_DISK=local
STORY_LIBRARY_DISK=s3_private
STUDIO_PRIVATE_DRIVER=scoped
EXPENSES_MEDIA_DISK=local
AWS_DEFAULT_REGION=eu-central-1
AWS_BUCKET=YOUR_ISOLATED_STAGING_BUCKET
AWS_USE_PATH_STYLE_ENDPOINT=false
```

Hostinger needs no Studio/expense override: `STUDIO_PRIVATE_DRIVER=local`, `EXPENSES_MEDIA_DISK=local`; keep its current private/public/processing disks. Omit `STORY_LIBRARY_DISK` to inherit `PRIVATE_MEDIA_DISK`, or set it explicitly to `local` on Hostinger and `s3_private` on AWS.

No new database schema migrations or Composer dependencies are introduced. The only new data conversion is the **existing**, explicit `media:migrate-disk-references` command now including library records. Studio paths and expense records are not rewritten. Any unrelated pending database migrations from earlier releases need their own review.

## Stage the pinned branch

1. Back up the staging DB, existing disk-reference values, config and local media. Use a separate release checkout; do not change a live production checkout. Stop staging queue workers/schedulers and incoming writes during reference conversion. Disable outbound WhatsApp, push, email, payment callbacks and paid AI calls in staging; use synthetic fixtures and mocks/sandbox integrations only. Don't enable production automation.
2. Fetch `codex/aws-final-production-migration` into the staging checkout. Set `RELEASE` to the full reviewed SHA supplied with the handoff, then verify and check out that exact revision:

   ```bash
   git fetch origin codex/aws-final-production-migration
   test "$(git rev-parse FETCH_HEAD)" = "$RELEASE"
   git switch --detach "$RELEASE"
   composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
   ```

3. Install the staging-only environment above. Populate the **staging** bucket with authorized fixture copies at the exact prefixes, and local public/expense copies at the table's paths. Existing expense paths already beginning `expenses/` must not gain another `private/` or `expenses/` prefix. Keep old local library originals until verification and rollback windows have passed. Ensure web and queue workers can write local processing directories; do not expose private storage through a web symlink.
4. Refresh caches and inspect the schema, without automatically applying unrelated pending migrations:

   ```bash
   php artisan optimize:clear
   php artisan migrate:status
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

5. Read-only verification and dry run (public stays local):

   ```bash
   php artisan media:verify-s3-references --target-public=public --missing-only
   php artisan media:migrate-disk-references --target-public=public
   ```

   Resolve every missing/error row before applying. A library row with a present PDF but a missing nonempty cover path is **not** eligible. The migration skips missing files rather than aborting all eligible rows; a zero exit status alone is not proof of completeness. Expenses are excluded intentionally; verify their files with the protected admin routes below. Studio has no disk-reference rewrite: verify published assets and covers at `private/studio-private/`, not `private/settings/`.
6. After explicit staging verification, apply to the **staging DB only**, then recheck references now on S3:

   ```bash
   php artisan media:migrate-disk-references --target-public=public --apply
   php artisan media:verify-s3-references --source-private=s3_private --target-public=public --missing-only
   php artisan media:migrate-disk-references --target-public=public
   ```

   Check that no expected local library rows remain and that expense attachment disks/paths and financial values match the backup exactly. The repeat migration must not create duplicates, change paths or copy/delete files. Restart only staging workers using their normal supervisor; no production worker action is authorized here.

## Staging acceptance checks

- Open a migrated published library story through both web and mobile API. Read PDF fully and request `Range: bytes=0-99`: expect `206`, correct `Content-Range` and 100-byte body. Check cover, reader, draft denial, and a new upload. Temporary `media-responses` files should be removed after responses; bucket objects remain private.
- Sign in with an authorized Studio employee; refresh settings and download published back cover/logo/templates. Compare bytes/SHA-256 with metadata. Confirm anonymous/unpublished access is denied. Upload/publish a synthetic asset and verify its S3 key starts `private/studio-private/settings/`. Exercise cover generation with a **mock provider**, or only after a separate explicit approval for any billable call; check reference/output paths and authorized download. Retention cleanup must never touch settings assets.
- Open/download a copied legacy expense attachment and an `expense_attachments` record. Upload an additional synthetic receipt and replace a legacy test receipt. Confirm local `expenses/...` paths, unchanged recorded historical paths, and no writes to S3 for receipts. Do not change real financial data.
- Check one existing private order attachment/photo, public catalog image, production preview PDF/Range, and local processing job. Public catalog still uses `/storage/...`; only the public local root is symlinked.
- Record actual staging URL, deployed SHA, route/status evidence, storage keys, checksum results and reference report. Passing local tests is not a staging/production verification claim.

## Rollback

Keep a DB/config/media snapshot from before conversion. Prefer restoring the staging DB/config snapshot together. Merely setting `STORY_LIBRARY_DISK=local` does not change existing row disks. Before changing any migrated reference back to local, verify **all** its PDF/cover files exist locally, including files uploaded after cutover. Similarly, only switch `STUDIO_PRIVATE_DRIVER` to `local` after copying any new Studio assets back to `storage/app/studio-private` with their original logical paths. Never delete S3 or local originals during rollback. Public catalog and expenses require no disk conversion.

## Reproducing storage integration tests

`AwsMigrationStorageTest` runs in the normal suite using real scoped Flysystem adapters over isolated local fixtures. For actual S3 adapter coverage, run the same test with `HEROKID_TEST_MINIO=1`, isolated SQLite, and a disposable MinIO container named `herokid-migration-minio` on a private Docker network. The test hardcodes a test-only bucket and dummy emulator credentials, uses a new UUID root per test, and removes only that root's objects. It cannot select the production bucket or endpoint. No live provider call is made. Test reports must distinguish this emulator run from verification on AWS.
