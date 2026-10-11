#!/usr/bin/env bash
# Nightly logical backup of the platform database.
#
#   PGHOST PGPORT PGUSER PGPASSWORD PGDATABASE   standard libpq variables
#   BACKUP_DIR              where dumps are kept locally (default ./backups)
#   BACKUP_GPG_RECIPIENT    encrypt to this GPG key (required in production)
#   BACKUP_UPLOAD_CMD       run with the file path appended, e.g.
#                           "aws s3 cp --sse aws:kms" + " s3://bucket/db/"
#   BACKUP_RETENTION_DAYS   local copies older than this are deleted (default 14)
#   PG_BIN                  directory of pg_dump if it is not on PATH
#
# Output: <db>-<UTC timestamp>.dump[.gpg] plus a .sha256 next to it.
# Point-in-time recovery comes from WAL archiving on the server (see
# docs/runbooks/backup-restore.md); this dump is the independent second copy.
set -euo pipefail

: "${PGDATABASE:?set PGDATABASE}"
BACKUP_DIR="${BACKUP_DIR:-./backups}"
RETENTION="${BACKUP_RETENTION_DAYS:-14}"
PG_DUMP="${PG_BIN:+$PG_BIN/}pg_dump"

if [[ -z "${BACKUP_GPG_RECIPIENT:-}" && "${APP_ENV:-}" == "production" ]]; then
    echo "BACKUP_GPG_RECIPIENT is required in production" >&2
    exit 1
fi

# Dumps hold every tenant's data: readable by this user only.
umask 077
mkdir -p "$BACKUP_DIR"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
file="$BACKUP_DIR/$PGDATABASE-$stamp.dump"

# Whatever fails below, never leave a partial or unencrypted dump behind.
plain="$file"
trap 'rm -f "$plain.partial" "$file.gpg.partial"; [[ -n "${BACKUP_GPG_RECIPIENT:-}" ]] && rm -f "$plain"' EXIT

# Custom format: compressed, restorable in parallel and table by table.
"$PG_DUMP" --format=custom --compress=6 --no-password --file="$file.partial" "$PGDATABASE"
mv "$file.partial" "$file"

if [[ -n "${BACKUP_GPG_RECIPIENT:-}" ]]; then
    gpg --batch --yes --trust-model always --recipient "$BACKUP_GPG_RECIPIENT" --output "$file.gpg.partial" --encrypt "$file"
    mv "$file.gpg.partial" "$file.gpg"
    file="$file.gpg"
fi

(cd "$(dirname "$file")" && sha256sum "$(basename "$file")" > "$(basename "$file").sha256")

if [[ -n "${BACKUP_UPLOAD_CMD:-}" ]]; then
    # shellcheck disable=SC2086
    $BACKUP_UPLOAD_CMD "$file"
    # shellcheck disable=SC2086
    $BACKUP_UPLOAD_CMD "$file.sha256"
fi

find "$BACKUP_DIR" -maxdepth 1 -type f -name "$PGDATABASE-*" -mtime +"$RETENTION" -delete

echo "$file"
