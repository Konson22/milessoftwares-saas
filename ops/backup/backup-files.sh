#!/usr/bin/env bash
# Nightly backup of a product's private files (HR staff documents), the
# companion of backup.sh: the database row says a document exists, this
# archive holds the file.
#
#   ops/backup/backup-files.sh <source_directory> <name>
#
#   BACKUP_DIR              where archives are kept locally (default ./backups)
#   BACKUP_GPG_RECIPIENT    encrypt to this GPG key (required in production)
#   BACKUP_UPLOAD_CMD       run with the file path appended, e.g.
#                           "aws s3 cp --sse aws:kms" + " s3://bucket/files/"
#   BACKUP_RETENTION_DAYS   local copies older than this are deleted (default 14)
#
# Output: <name>-<UTC timestamp>.tar.gz[.gpg] plus a .sha256 next to it.
# Restore: verify the .sha256, gpg --decrypt, then tar -xzf into the
# product's storage/app (see docs/runbooks/backup-restore.md).
set -euo pipefail

src="${1:?usage: backup-files.sh <source_directory> <name>}"
name="${2:?usage: backup-files.sh <source_directory> <name>}"
BACKUP_DIR="${BACKUP_DIR:-./backups}"
RETENTION="${BACKUP_RETENTION_DAYS:-14}"

if [[ ! "$name" =~ ^[a-z0-9][a-z0-9-]*$ ]]; then
    echo "name must be lower-case letters, digits and -" >&2
    exit 1
fi
if [[ ! -d "$src" ]]; then
    echo "$src is not a directory" >&2
    exit 1
fi
if [[ -z "${BACKUP_GPG_RECIPIENT:-}" && "${APP_ENV:-}" == "production" ]]; then
    echo "BACKUP_GPG_RECIPIENT is required in production" >&2
    exit 1
fi

# Staff documents: readable by this user only.
umask 077
mkdir -p "$BACKUP_DIR"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
file="$BACKUP_DIR/$name-$stamp.tar.gz"

# Whatever fails below, never leave a partial or unencrypted archive behind.
plain="$file"
trap 'rm -f "$plain.partial" "$file.gpg.partial"; [[ -n "${BACKUP_GPG_RECIPIENT:-}" ]] && rm -f "$plain"' EXIT

# Paths inside the archive are relative to the source, so it restores anywhere.
tar --create --gzip --file="$file.partial" --directory="$src" .
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

find "$BACKUP_DIR" -maxdepth 1 -type f -name "$name-*" -mtime +"$RETENTION" -delete

echo "$file"
