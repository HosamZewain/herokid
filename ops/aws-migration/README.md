# HeroKid migration operations

Read `docs/aws-migration-execution-2026-10-29.md` before running anything. Production cutover remains gated by explicit user GO-LIVE approval.

- `staging-iam.py`: approved, idempotent staging S3/role/profile setup. Already executed through the authorized AWS console.
- `inventory.php`: read-only source filesystem inventory; execute through SSH stdin from the app root. Store JSON privately.
- `delta-sync.py`: default read-only plan; explicit production apply flag is not a substitute for human approval. Source inventory and object inventory must be refreshed after writes stop. S3 objects JSON maps keys to `Size` and `LastModifiedEpoch`. No deletion; bounded rsync to controller plus IAM upload through EC2. Tested using synthetic staging files.
- `local-media-sync.sh`: protected controller cache plus rsync of public images and expenses; default AWS destination dry-run. Never uses S3 or `--delete`.
- `check-media-baseline.py`: refuses newly missing media; recognizes only exact previously missing references still absent at source. Baseline acceptance must remain explicit.
- `export-hostinger.php`: native single-transaction stream; no Hostinger files are written. Password exists only in the dump child environment because the shared-host jail does not expose `/dev/fd`; never put it in argv or logs. Protect outputs and use pipefail. Schema-only mode is `MIGRATION_SCHEMA_ONLY=1`.
- `normalize-mariadb-dump.py`: removes only MariaDB's client sandbox directive. Preserve the original archive.
- `db-backup.sh` and backup units: installed/tested local backups. `upload-backup.sh` is prepared for verified production-role upload after GO-LIVE; recurring off-instance upload is not active yet.
- `production.env.overlay` / `prepare-production-env.php`: inactive production candidate, preserving production APP_KEY and AWS DB credentials. No static AWS keys. Refresh before cutover.
- `production-nginx.conf`: syntax-validated production vhosts; not activated. `renew-production-certificate-after-cutover.sh` must run and pass after public traffic points to AWS.
- `production-supervisor.conf`, scheduler units, `redis-production.conf`: reviewed production templates. Workers and scheduler must remain stopped until the approved transition. Redis AOF was separately enabled safely during preparation.
- `staging-fixtures.php` / `http-validation.py`: hard-coded staging-only synthetic fixture and HTTP checks. The fixture script refuses another DB/bucket.

Do not commit inventories, SQL dumps, dotenv files, private keys, raw job payloads or customer media. After applying templates, verify the live state; file creation alone is not deployment proof.
