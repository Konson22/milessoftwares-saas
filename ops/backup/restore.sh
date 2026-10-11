#!/usr/bin/env bash
# Restore a dump made by backup.sh into a NEW database and verify it.
# Never restores over an existing database.
#
#   ops/backup/restore.sh <file.dump[.gpg]> <target_database>
#
#   PGHOST PGPORT PGUSER PGPASSWORD   a role allowed to create databases
#   RESTORE_OWNER     owner of the restored objects (default: PGUSER)
#   PG_BIN            directory of pg_restore/psql if not on PATH
#   APP_DIR           application checkout used for verification (default .)
#   PHP               php binary (default php)
set -euo pipefail

src="${1:?usage: restore.sh <dump> <target_database>}"
target="${2:?usage: restore.sh <dump> <target_database>}"
bin="${PG_BIN:+$PG_BIN/}"
app="${APP_DIR:-.}"
php="${PHP:-php}"

if [[ ! "$target" =~ ^[a-z_][a-z0-9_]*$ ]]; then
    echo "target database name must be lower-case letters, digits and _" >&2
    exit 1
fi

if [[ -f "$src.sha256" ]]; then
    (cd "$(dirname "$src")" && sha256sum --check --status "$(basename "$src").sha256") \
        || { echo "checksum mismatch for $src" >&2; exit 1; }
    echo "checksum ok"
else
    echo "warning: no $src.sha256, integrity not checked" >&2
fi

work="$src"
if [[ "$src" == *.gpg ]]; then
    work="$(mktemp)"
    trap 'rm -f "$work"' EXIT
    gpg --batch --yes --output "$work" --decrypt "$src"
fi

exists="$("${bin}psql" -d postgres -Atc "SELECT 1 FROM pg_database WHERE datname = '$target'")"
if [[ "$exists" == "1" ]]; then
    echo "database $target already exists; pick a new name" >&2
    exit 1
fi

"${bin}createdb" --owner="${RESTORE_OWNER:-$PGUSER}" "$target"
"${bin}pg_restore" --dbname="$target" --no-owner --role="${RESTORE_OWNER:-$PGUSER}" --jobs=4 --exit-on-error "$work"
echo "restored into $target"

# Verification against the application: schema is current and the audit
# chain still verifies, which also proves no audit row was lost.
cd "$app"
DB_DATABASE="$target" "$php" artisan migrate:status --no-ansi | tail -n 3
DB_DATABASE="$target" "$php" artisan platform:audit-verify --no-ansi
"${bin}psql" -d "$target" -Atc "SELECT 'organizations', count(*) FROM organizations UNION ALL SELECT 'users', count(*) FROM users UNION ALL SELECT 'audit_logs', count(*) FROM audit_logs"
