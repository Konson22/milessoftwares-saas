# Runbook: Backup and Restore

## What is backed up

| Data                     | How                                                             | Where                                                      | Retention                   |
| ------------------------ | --------------------------------------------------------------- | ---------------------------------------------------------- | --------------------------- |
| Database (logical)       | `ops/backup/backup.sh` nightly 02:15 UTC (`miles-backup.timer`) | Off-site bucket, GPG-encrypted                             | 35 days in bucket, 14 local |
| Database (point in time) | WAL archiving on the PostgreSQL server (e.g. pgBackRest/WAL-G)  | Off-site bucket                                            | 7 days of WAL               |
| Audit trail              | `platform:audit-export` hourly, NDJSON with hashes              | `audit` disk: write-once (S3 Object Lock, compliance mode) | 7 years                     |
| Passport keys, `.env`    | Secret store                                                    | —                                                          | —                           |

Redis holds sessions, cache and queued jobs only. Losing it signs everyone out and drops queued jobs;
webhooks and provisioning steps are kept in the database and retried by the scheduler, so nothing is
lost permanently.

## Backup configuration

`/etc/miles/backup.env` (mode 0600, owner `miles-backup`):

```
PGHOST=…  PGPORT=5432  PGUSER=miles_backup  PGPASSWORD=…  PGDATABASE=miles_platform
BACKUP_DIR=/var/backups/miles
BACKUP_GPG_RECIPIENT=backups@milessoftwares.com
BACKUP_UPLOAD_CMD=aws s3 cp --sse aws:kms
```

`miles_backup` needs only `CONNECT` and `SELECT` on every table (`pg_read_all_data`).
`backup.sh` refuses to run in production without `BACKUP_GPG_RECIPIENT`, writes files mode 0600, and
deletes the unencrypted dump on any failure (checked on 2026-10-10).

## Restore drill (quarterly, and before go-live)

On a staging or scratch server, never on the production database server:

```bash
aws s3 cp s3://…/db/miles_platform-<stamp>.dump.gpg .
aws s3 cp s3://…/db/miles_platform-<stamp>.dump.gpg.sha256 .
PGHOST=… PGUSER=<role with CREATEDB> APP_DIR=/srv/miles/current/central-app \
  ops/backup/restore.sh miles_platform-<stamp>.dump.gpg miles_restore_<yyyymmdd>
```

`restore.sh`:

1. Checks the SHA-256 (refuses on mismatch), decrypts with the offline GPG key.
2. Refuses to restore over an existing database. It always creates a new one.
3. Restores with `pg_restore --exit-on-error`.
4. Verifies: migration status, **`platform:audit-verify` on the restored copy** (proves no audit row was
   lost or altered), row counts of organizations, users and audit logs.

Record in the drill log: dump timestamp, restore duration, the three counts, verification output.
Compare the audit count and last hash with the newest `audit_exports` row of the same time.

## Restoring production

Only with the incident lead's approval (see [incidents.md](incidents.md)).

1. `php artisan down` on every node.
2. Point-in-time recovery to just before the incident, into a **new** cluster or database. Use the
   nightly dump only if WAL recovery is not possible.
3. Run the `restore.sh` verification steps against it.
4. Switch `DB_HOST`/`DB_DATABASE` in `/srv/miles/shared/.env`, `php artisan optimize`, reload php-fpm,
   `queue:restart`.
5. `php artisan platform:preflight`, then `php artisan up`.
6. Keep the old database untouched for the investigation.

Data written between the recovery point and the incident is lost. Products reconcile through their
own idempotent APIs. Tell affected organizations.

## Audit export

`platform:audit-export` continues after the highest `audit_exports.last_sequence`. Files are
`YYYY/MM/audit-<first>-<last>.ndjson`; each line has `prev_hash` and `hash`, so a copy can be checked
without the database. Upload failure means no `audit_exports` row is written, so the next run retries
the same range. Alert: the newest `audit_exports.created_at` older than 2 hours.
