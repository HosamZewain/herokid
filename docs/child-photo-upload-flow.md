# Child Photo Upload Flow

## Root Cause

The old story order form submitted `photos[]` together with all child data in one multipart request. On mobile networks and Hostinger shared hosting this made one large request responsible for validation, upload, storage, cart creation, and form errors. If one image was slow, too large, unsupported, or timed out, the full request failed and the customer lost progress.

## New Flow

1. The story page creates a server-side upload session stored in the Laravel session.
2. The customer selects up to `PHOTO_UPLOAD_MAX_FILES` photos.
3. Browser-side JavaScript uploads each photo independently to `POST /photo-uploads`.
4. Upload concurrency is limited by `PHOTO_UPLOAD_CONCURRENCY`, currently `2`.
5. Each upload receives progress, success, failure, retry, and remove UI.
6. Laravel returns an opaque temporary upload UUID.
7. The final story form sends only `photo_upload_ids[]` plus the scoped `upload_session_token`.
8. The cart stores the same private file paths used by the existing order, admin, and Production Studio flows.
9. Checkout marks attached temporary uploads with the final order ID.

The legacy `photos[]` path remains as a transitional fallback for old clients and tests, but the public Blade form no longer submits image files during final story submission.

## Storage And Privacy

- Temporary child photos are stored on the private Laravel `local` disk, under `temporary-uploads/child-photos/...`.
- The `local` disk points to `storage/app/private` in this project.
- Temporary photo IDs are UUIDs, not sequential database IDs.
- Physical paths and private URLs are never exposed to public users.
- Preview images are served only through `GET /photo-uploads/{publicId}` and must match the current upload session hash.
- Temporary upload routes are marked private/no-store by the security cache middleware.
- The frontend stores only opaque temporary upload IDs in `localStorage`; no image binary data, base64, or private URLs are stored there.
- No child photo data is sent to Google Analytics, Meta Pixel, `dataLayer`, or any external analytics system.

## Supported Formats And Limits

Configured in `.env`:

```env
PHOTO_UPLOAD_MAX_FILES=5
PHOTO_UPLOAD_MAX_SIZE_MB=15
PHOTO_UPLOAD_CONCURRENCY=2
PHOTO_UPLOAD_TEMP_RETENTION_HOURS=24
PHOTO_UPLOAD_MAX_LONG_EDGE=2560
PHOTO_UPLOAD_JPEG_QUALITY=90
```

Accepted MIME types:

- JPEG / JPG
- PNG
- WebP
- HEIC / HEIF

HEIC/HEIF is accepted at upload time, but PHP on shared hosting may not decode dimensions for those formats. The current server validation therefore validates MIME and size for HEIC/HEIF and avoids claiming server-side conversion support.

## Client-Side Optimization

Before upload, the browser attempts to resize JPEG, PNG, and WebP images when the long edge is larger than `PHOTO_UPLOAD_MAX_LONG_EDGE`. The resize:

- does not upscale smaller images
- strips unnecessary EXIF metadata by re-encoding through canvas
- preserves enough quality for child identity references using `PHOTO_UPLOAD_JPEG_QUALITY`
- falls back to the original file if browser image decoding is unavailable

Server-side validation remains authoritative.

## Temporary Upload Lifecycle

Statuses:

- `uploaded`: temporary file exists and may be attached to the current session cart
- `attached`: file has been attached to a cart item and later to an order
- `expired`: unattached upload expired or was removed

Rules:

- One upload ID can only be attached once.
- Upload IDs must belong to the current upload session.
- Authenticated uploads are associated with the user when available.
- Expired, missing, or foreign upload IDs are rejected with Arabic validation errors.
- Final story submission is short and does not perform expensive image processing.

## Cleanup

Expired unattached temporary uploads are cleaned by:

```bash
php artisan photo-uploads:cleanup
```

The Laravel scheduler also runs it hourly.

For Hostinger, keep using the wrapper script instead of adding long artisan commands directly to hPanel. A safe wrapper is:

```bash
#!/bin/bash
APP_DIR="/home/u470070883/domains/hero-kid.com/public_html"
PHP_BIN="/usr/bin/php"
LOCK_FILE="/tmp/herokid-queue.lock"

cd "$APP_DIR" || exit 1
mkdir -p storage/logs

exec 9>"$LOCK_FILE"
flock -n 9 || exit 0

$PHP_BIN artisan schedule:run >> storage/logs/scheduler.log 2>&1
$PHP_BIN artisan queue:work database --queue=default --stop-when-empty --tries=3 --backoff=30 --timeout=300 >> storage/logs/queue.log 2>&1
```

Cron entry:

```bash
* * * * * /bin/bash /home/u470070883/run-herokid-queue.sh
```

Do not duplicate `#!/bin/bash` blocks inside the script.

## Hostinger PHP Settings

Recommended:

- `upload_max_filesize` >= `15M`
- `post_max_size` >= `32M`
- `max_file_uploads` >= `20`
- `max_execution_time` >= `120`
- `max_input_time` >= `120`
- `memory_limit` >= `256M`

The new flow avoids one huge request, but PHP still needs enough room for one optimized/mobile image at a time.

## Future Direct Object Storage

The implementation uses Laravel Filesystem and can later move temporary uploads to private S3-compatible storage or Cloudflare R2. The public story form should still submit opaque upload IDs only; only the storage implementation and preview/download controllers would change.

## Verification

Useful commands:

```bash
php artisan test --filter=TemporaryPhotoUploadTest
php artisan test --filter=CartCheckoutTest
npm run build
php artisan photo-uploads:cleanup
```

Browser verification:

1. Open a story page.
2. Select five large photos.
3. Confirm only two upload at the same time.
4. Confirm each row shows progress and can retry independently.
5. Submit the form and verify the final request contains `photo_upload_ids[]`, not `photos[]`.
6. Confirm `/storage/temporary-uploads/...` is not publicly accessible.

## Rollback

If urgent rollback is needed, revert the Blade form and controllers to the legacy `photos[]` multipart behavior and keep the `temporary_photo_uploads` table in place. The new table is additive and does not alter existing order photo paths.
