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
```

`s3_private` is scoped to `private/` and `s3_public` is scoped to `public/`. Both prefixes remain private in S3. Do not switch `PUBLIC_MEDIA_DISK` to `s3_public` until a private-bucket CloudFront distribution and its URL strategy are configured.

`PROCESSING_DISK` must always reference a Laravel `local` driver. Image dimensions, ImageMagick/GD, mPDF, file hashing, and other path-based tools materialize unique local working files and remove them after processing.

Shared-hosting installations need no new environment values. Their defaults remain `local`, `public`, and `local` respectively.

## Deployment order

1. Attach an IAM role to EC2 that can read, write, and delete only the required bucket prefixes.
2. Confirm the existing objects are present under `private/` and `public/` in `herokid-prod-media-2026`.
3. Deploy the application and run `composer install --no-dev --optimize-autoloader`.
4. Add the environment values above, then run `php artisan optimize:clear` and rebuild the normal production caches.
5. Verify database references before changing any disk value:

```bash
php artisan media:verify-s3-references --missing-only
```

The verification command is read-only. It reports the table, model/row ID, path, target disk, and whether each object exists.

6. Preview the disk-reference update. Dry-run is the default and changes nothing:

```bash
php artisan media:migrate-disk-references
```

7. Review missing/error rows. Only after the target objects are confirmed, apply eligible updates:

```bash
php artisan media:migrate-disk-references --apply
```

The update command is idempotent, never copies or deletes files, skips missing target objects, and changes a row only while its recorded source disk still matches. Historical schemas without a disk column are verified but require no database rewrite; the application resolves them through `media.private_disk` or `media.public_disk`.

## Rollback

Application rollback is configuration-first: set `PRIVATE_MEDIA_DISK=local`, keep `PUBLIC_MEDIA_DISK=public`, clear/rebuild Laravel caches, and deploy the previous release. The migration command never deletes the original local objects. Database disk values already changed to `s3_private` should only be reverted through a reviewed, existence-checked operation after the corresponding local files have been confirmed.
