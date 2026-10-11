#!/usr/bin/env bash
# Zero-downtime release on a single host (see docs/runbooks/deploy.md).
#
#   ops/deploy/deploy.sh <git ref> [--bootstrap]
#
# --bootstrap is for the first deploy of an environment only: it migrates and
# creates the first-party OIDC clients (written to shared/.env) before the
# configuration gate, which refuses to pass without them.
#
# Layout under $BASE (default /srv/miles):
#   repo/            bare clone
#   releases/<id>/   one checkout per release
#   shared/.env      production environment (APP_ENV=production)
#   shared/storage/  logs, framework cache; Passport keys come from the env
#   current -> releases/<id>
#
# Migrations and grants run as the schema owner (MIGRATION_DB_USERNAME /
# MIGRATION_DB_PASSWORD in the deploy user's environment, never in .env);
# the application keeps running as miles_app.
#
# Migrations must be backward compatible with the previous release
# (expand, deploy, contract), so rollback.sh never has to undo a migration.
set -euo pipefail

ref="${1:?usage: deploy.sh <git ref> [--bootstrap]}"
bootstrap="${2:-}"
if [[ -n "$bootstrap" && "$bootstrap" != "--bootstrap" ]]; then
    echo "unknown option: $bootstrap" >&2
    exit 1
fi
BASE="${BASE:-/srv/miles}"
KEEP="${KEEP_RELEASES:-5}"
PHP="${PHP:-php}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.4-fpm}"
HEALTH_URL="${HEALTH_URL:-https://milessoftwares.com/up}"
: "${MIGRATION_DB_USERNAME:?set MIGRATION_DB_USERNAME}"
: "${MIGRATION_DB_PASSWORD:?set MIGRATION_DB_PASSWORD}"

env_value() { grep -E "^$1=" "$BASE/shared/.env" | tail -n 1 | cut -d= -f2- | tr -d "\"'"; }

id="$(date -u +%Y%m%d%H%M%S)"
release="$BASE/releases/$id"

# A bare clone has no fetch refspec: name it, or branches would never move.
git --git-dir="$BASE/repo" fetch --quiet --prune --tags origin "+refs/heads/*:refs/heads/*"
mkdir -p "$release"
git --git-dir="$BASE/repo" --work-tree="$release" checkout --quiet --force "$ref" -- .
git --git-dir="$BASE/repo" rev-parse "$ref" > "$release/REVISION"

ln -sfn "$BASE/shared/.env" "$release/.env"
rm -rf "$release/storage"
ln -sfn "$BASE/shared/storage" "$release/storage"

cd "$release"
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress
npm ci --no-audit --no-fund
# Client bundle plus the SSR bundle for the public site.
npm run build:ssr
# node_modules stays: the SSR bundle imports React and Inertia from it at runtime.

migrate_and_grant() {
    DB_USERNAME="$MIGRATION_DB_USERNAME" DB_PASSWORD="$MIGRATION_DB_PASSWORD" "$PHP" artisan migrate --force --no-ansi
    PGPASSWORD="$MIGRATION_DB_PASSWORD" psql --no-psqlrc -v ON_ERROR_STOP=1 -q \
        -h "$(env_value DB_HOST)" \
        -p "$(env_value DB_PORT)" \
        -U "$MIGRATION_DB_USERNAME" \
        -d "$(env_value DB_DATABASE)" \
        -f ops/postgres/grants.sql
}

if [[ "$bootstrap" == "--bootstrap" ]]; then
    migrate_and_grant
    # Creates only the clients that are missing; safe to repeat.
    "$PHP" artisan platform:oidc-clients --write-env --no-ansi
fi

# Stop here if the environment is not production-safe. The database checks
# run after migrations, so a first deploy can create audit_logs first.
"$PHP" artisan platform:preflight --skip-database --no-ansi

migrate_and_grant

# As the runtime role: not a superuser, audit_logs append-only, chain intact.
"$PHP" artisan platform:preflight --no-ansi

"$PHP" artisan platform:catalog-import --no-ansi
"$PHP" artisan optimize --no-ansi

# Atomic switch.
ln -sfn "$release" "$BASE/current.tmp"
mv -T "$BASE/current.tmp" "$BASE/current"

sudo systemctl reload "$PHP_FPM_SERVICE"
"$PHP" "$BASE/current/artisan" queue:restart --no-ansi
# systemd restarts the SSR server from the new release.
"$PHP" "$BASE/current/artisan" inertia:stop-ssr --no-ansi || true

if ! curl --fail --silent --show-error --max-time 10 "$HEALTH_URL" > /dev/null; then
    echo "health check failed: run ops/deploy/rollback.sh" >&2
    exit 1
fi

# Keep the newest $KEEP releases (ids sort by time; current is the newest).
ls -1d "$BASE"/releases/* | sort | head -n -"$KEEP" | xargs -r rm -rf

echo "released $id ($(cat "$release/REVISION"))"
