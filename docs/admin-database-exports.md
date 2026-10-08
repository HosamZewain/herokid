# Admin database exports

## Access and scope

Administration & Security → **تصدير قاعدة البيانات** (`/admin/database-exports`).
Requires an active web admin account and the sensitive `database_exports.manage`
permission. The default Owner role includes it; the default Administrator role
does not. The additive migration grants it only to active legacy admins with a
direct permission to manage admin permissions. Explicitly grant it to other
trusted owners as needed; do not grant all permissions to all employees.

Both requesting and downloading require the current account password. Exports
are visible/downloadable only by their requester. Download is a CSRF-protected
POST, rechecks permissions/ownership/expiry, streams from private storage, and
is never a public share link. Requests/downloads are audited without passwords.

The `.sql.gz` includes the **entire database**: tables, data, views, triggers,
routines, and events. It includes private customer records and internal settings,
potentially encrypted credentials and authentication records. Treat it as a
secret. It does **not** include images, attachments, `.env`, or `APP_KEY`. Back up
media separately; preserve the original application key securely for encrypted
database values. This is not a full application/disaster-recovery backup by itself.

## Processing and server prerequisites

Web requests only enqueue metadata. The existing Laravel scheduler runs
`database-exports:process` every minute in a separate background CLI process,
with an overlap lock. Only one queued/processing export is allowed globally.
No production queue worker or web request performs the dump.

Required: MySQL/MariaDB using InnoDB, `mysqldump` or `mariadb-dump` in the CLI
PATH, PHP `proc_open` and zlib, writable private processing storage, and database
privileges to read all tables/views/triggers/routines/events. Refuse non-InnoDB
tables rather than claim a transactionally consistent lock-free snapshot.
Avoid schema migrations/DDL while exporting. Native single-transaction export
does not lock normal InnoDB writes, but can consume disk and database resources.

Optional `DATABASE_EXPORT_MYSQL_BINARY=/absolute/path/to/mysqldump` is needed
only when the executable is not in the PHP CLI PATH. No new AWS credentials.
Uses existing `media.processing_disk` (local filesystem required) and
`media.private_disk` (`local` on Hostinger, `s3_private` on AWS). Public disks
and public directory roots are rejected. Credentials use a temporary mode-0600
defaults file inside a mode-0700 directory, not command-line arguments.

Files are dumped, gzip-compressed, and uploaded using bounded-memory streams.
Failed dumps do not become downloadable. Partial objects/failed DB save copies
are removed or retained solely for a later cleanup retry. Raw connection/process
errors are never stored in export records. Dump timeout is 15 minutes; an
interrupted processing record is recovered after 30 minutes.

Copies expire 24 hours after completion. Cleanup deletes only exact export-copy
paths and stale private work directories, **never database records or media**.
Queued requests expire after two hours if the scheduler is not operating.

## Deployment

Run the additive migration and `php artisan admin-permissions:sync` (without
`--grant-existing-admins`) so the existing Owner role receives the new permission.
Normal config/route/view cache rebuild applies. Ensure exactly one existing cron
invokes `php artisan schedule:run` each minute. `php artisan schedule:list` must
show `database-exports:process`. Do not add a duplicate cron if one already exists.

Manual processing/cleanup, outside a web request:

```sh
php artisan database-exports:process
php artisan database-exports:cleanup
```

If unavailable, the page clearly disables requests. A full dump requiring more
privileges, a nontransactional database, or a disabled CLI executable should use
the hosting provider's secure backup tooling instead of a partial PHP export.

## Restore verification

No web restore endpoint is provided. Restore into a **separate empty database**
with the matching MySQL/MariaDB tools, never over a live database casually.
The dump has no `CREATE DATABASE`/`USE` instruction; choose the destination
explicitly. GTID state is excluded for Oracle MySQL. Views/routines/triggers may
require appropriate definer privileges on a different server.

Disable scheduler, queue workers and external integrations on a restored test
environment before booting it: a full copy includes pending jobs, tokens and
export-request rows. Clear restored export requests/jobs deliberately before
enabling scheduling. Keep the original `APP_KEY` secure; never print it or include
it in public files. Verify data and schema, and back up media separately.

`DatabaseDumpRoundTripTest` uses uniquely named synthetic databases, restores a
real native dump, and verifies UTF-8/emoji/newlines, NULL, binary bytes, foreign
keys, views, triggers, routines and events. It requires native clients and a test
account allowed to create/drop isolated test databases. It skips on unsupported
test drivers; do not interpret a skipped native test as restore verification.
