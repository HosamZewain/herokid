# AWS hybrid media storage

HeroKid keeps persistent private media in a private S3 prefix while all work that requires a real filesystem path remains on the EC2 instance. Public catalog media remains on the local `public` disk until CloudFront is configured.

## Production environment

The S3 bucket must stay private. Authentication uses the EC2 instance profile/IAM role; do not add `AWS_ACCESS_KEY_ID` or `AWS_SECRET_ACCESS_KEY` to the environment.

```dotenv
FILESYSTEM_DISK=local

AWS_DEFAULT_REGION=eu-central-1
AWS_BUCKET=herokid-prod-media-2026
AWS_USE_PATH_STYLE_ENDPOINT=false

PRIVATE_MEDIA_DISK=s3_private
PUBLIC_MEDIA_DISK=public
PROCESSING_DISK=local
STORY_LIBRARY_DISK=s3_private
STUDIO_PRIVATE_DRIVER=scoped
EXPENSES_MEDIA_DISK=local
```

`s3_private` is scoped to `private/` and `s3_public` is scoped to `public/`. Both prefixes remain private in S3. Do not switch `PUBLIC_MEDIA_DISK` to `s3_public` until a private-bucket CloudFront distribution and its URL strategy are configured.

`PROCESSING_DISK` must always reference a Laravel `local` driver. Image dimensions, ImageMagick/GD, mPDF, file hashing, and other path-based tools materialize unique local working files and remove them after processing.

Shared-hosting installations need no new environment values. Their defaults remain `local`, `public`, and `local` respectively.

## Story Library, Remote Studio, and expenses

- Story Library's `library_stories.disk` applies to both `pdf_path` and `cover_path`. The migration registry checks **all nonempty paths** before changing the disk. Logical `story-library/...` paths map to `private/story-library/...` on S3. New stories use `STORY_LIBRARY_DISK`; edits retain the story's recorded disk. Migrate old records before reopening uploads on AWS. Public web/API PDF and cover routes continue streaming through Laravel; PDFs retain HTTP Range support via temporary local materialization and cleanup.
- `studio_private` defaults to the local `storage/app/studio-private` directory. `STUDIO_PRIVATE_DRIVER=scoped` selects the existing base `s3` disk with the fixed prefix `private/studio-private`, private visibility, and exceptions on storage failures. Logical `settings/...` and `covers/...` paths and database records do not change. Never alias this disk to `s3_private`. Integrity checks hash a read stream, not a local path.
- Both old `expense_transactions.attachment_path` and new uploads use `EXPENSES_MEDIA_DISK` (default `local`, root `storage/app/private`). Existing `expense_attachments` keep their recorded disk and path. Neither expense table participates in S3 reference migration/verification. Keep the copied `storage/app/private/expenses/` tree on EC2 with restricted filesystem permissions and backups. No financial values are changed.
- Studio settings/cover controllers use adapter reads, writes and downloads. Cover generation jobs and retention cleanup use exact logical paths. Local GD inspection is limited to PHP uploaded temporary files. Existing Agent/Production Studio media paths remain on their current hybrid-storage flow; local legacy fallback and processing files are unchanged.

See [AWS staging migration validation](aws-final-migration-staging.md) for the isolated staging procedure. This change does not deploy either host.

## Admin Media Library

Media Library upload sessions are deliberately split across two storage classes:

- Incomplete chunks and `assembled.tmp` use `PROCESSING_DISK`. This must remain a local driver because completion performs ordered assembly, MIME inspection, size verification, and SHA-256 hashing through real filesystem paths.
- A validated completed file is streamed to `PRIVATE_MEDIA_DISK` under `admin/media-library/files/YYYY/MM/`. The database stores the actual configured disk name and logical path.

On AWS, the final object is therefore stored below `private/admin/media-library/files/` in the private bucket. On shared hosting, the absent `PRIVATE_MEDIA_DISK` setting safely falls back to the existing local private disk. Public share links always pass through Laravel; the S3 bucket and object are never made public.

If persistent storage fails, no completed database record is created and the upload session remains retryable. If database creation fails after the object upload, the newly uploaded object is removed. The incomplete-upload cleanup command only scans upload-session paths on `PROCESSING_DISK` and never scans or deletes completed Media Library objects.

## Deployment order

1. Attach an IAM role to EC2 that can read, write, and delete only the required bucket prefixes.
2. Confirm private objects are present under `private/` (including `private/story-library/` and `private/studio-private/`) in `herokid-prod-media-2026`. Keep public catalog files under EC2 `storage/app/public`, and expenses under `storage/app/private/expenses`.
3. Deploy the application and run `composer install --no-dev --optimize-autoloader`.
4. Add the environment values above, then run `php artisan optimize:clear` and rebuild the normal production caches.
5. Verify database references before changing any disk value:

```bash
php artisan media:verify-s3-references --target-public=public --missing-only
```

The verification command is read-only. It reports the table, model/row ID, path, target disk, and whether each object exists.

6. Preview the disk-reference update. Dry-run is the default and changes nothing:

```bash
php artisan media:migrate-disk-references --target-public=public
```

7. Review missing/error rows. Only after the target objects are confirmed, apply eligible updates:

```bash
php artisan media:migrate-disk-references --target-public=public --apply
```

The update command is idempotent, never copies or deletes files, skips missing target objects, and changes a row only while its recorded source disk still matches. Always pass `--target-public=public` for this hybrid architecture; the command's general default is still `s3_public`. Historical schemas without a disk column are verified but require no database rewrite; the application resolves them through `media.private_disk` or `media.public_disk`. Expenses are a deliberate exception and resolve separately as described above.

## Rollback

Application rollback is configuration-first: set `PRIVATE_MEDIA_DISK=local`, keep `PUBLIC_MEDIA_DISK=public`, clear/rebuild Laravel caches, and deploy the previous release. The migration command never deletes the original local objects. Database disk values already changed to `s3_private` should only be reverted through a reviewed, existence-checked operation after the corresponding local files have been confirmed.
