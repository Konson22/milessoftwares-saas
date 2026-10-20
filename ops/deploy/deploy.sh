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
#   releases/<id>/   one checkout of the repository per release; the Laravel
#                    app is releases/<id>/central-app
#   shared/.env      production environment (APP_ENV=production)
#   shared/storage/  logs, framework cache; Passport keys come from the env
#   shared/products/<code>/{.env,storage/}
#                    each product's own environment and storage
#   current -> releases/<id>
#
# Products ($PRODUCTS, default "pharmacy finance") ship in the same release:
# releases/<id>/<code>-app, each with its own database (migrated and granted
# like the platform's), its catalog imported into the platform, and its own
# php-fpm pool, queue worker and scheduler (ops/php-fpm, ops/systemd).
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
HEALTH_URL="${HEALTH_URL:-https://milesoftwares.com/up}"
PRODUCTS="${PRODUCTS-pharmacy finance}"
: "${MIGRATION_DB_USERNAME:?set MIGRATION_DB_USERNAME}"
: "${MIGRATION_DB_PASSWORD:?set MIGRATION_DB_PASSWORD}"

# env_value KEY [env file]: a value from the platform's (or a product's) .env.
env_value() { grep -E "^$1=" "${2:-$BASE/shared/.env}" | tail -n 1 | cut -d= -f2- | tr -d "\"'"; }

for code in $PRODUCTS; do
    if [[ ! -f "$BASE/shared/products/$code/.env" ]]; then
        echo "missing $BASE/shared/products/$code/.env (see docs/runbooks/deploy.md, Products)" >&2
        exit 1
    fi
done

id="$(date -u +%Y%m%d%H%M%S)"
release="$BASE/releases/$id"

# A bare clone has no fetch refspec: name it, or branches would never move.
git --git-dir="$BASE/repo" fetch --quiet --prune --tags origin "+refs/heads/*:refs/heads/*"
mkdir -p "$release"
git --git-dir="$BASE/repo" --work-tree="$release" checkout --quiet --force "$ref" -- .
git --git-dir="$BASE/repo" rev-parse "$ref" > "$release/REVISION"

app="$release/central-app"
ln -sfn "$BASE/shared/.env" "$app/.env"
rm -rf "$app/storage"
ln -sfn "$BASE/shared/storage" "$app/storage"

cd "$app"
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress
npm ci --no-audit --no-fund
# Client bundle plus the SSR bundle for the public site.
npm run build:ssr
# node_modules stays: the SSR bundle imports React and Inertia from it at runtime.

# Products: install and build each in its own folder of the release.
for code in $PRODUCTS; do
    product="$release/$code-app"
    if [[ ! -f "$product/artisan" ]]; then
        echo "no product $code-app in $ref" >&2
        exit 1
    fi
    ln -sfn "$BASE/shared/products/$code/.env" "$product/.env"
    rm -rf "$product/storage"
    ln -sfn "$BASE/shared/products/$code/storage" "$product/storage"
    (
        cd "$product"
        composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress
        npm ci --no-audit --no-fund
        npm run build
        # Client-only bundle: node_modules is not needed at runtime.
        rm -rf node_modules
    )
done

migrate_and_grant() {
    DB_USERNAME="$MIGRATION_DB_USERNAME" DB_PASSWORD="$MIGRATION_DB_PASSWORD" "$PHP" artisan migrate --force --no-ansi
    PGPASSWORD="$MIGRATION_DB_PASSWORD" psql --no-psqlrc -v ON_ERROR_STOP=1 -q \
        -h "$(env_value DB_HOST)" \
        -p "$(env_value DB_PORT)" \
        -U "$MIGRATION_DB_USERNAME" \
        -d "$(env_value DB_DATABASE)" \
        -f ../ops/postgres/grants.sql
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

# Each product's database, the same way (migrations backward compatible too).
for code in $PRODUCTS; do
    product_env="$BASE/shared/products/$code/.env"
    (
        cd "$release/$code-app"
        DB_USERNAME="$MIGRATION_DB_USERNAME" DB_PASSWORD="$MIGRATION_DB_PASSWORD" "$PHP" artisan migrate --force --no-ansi
        PGPASSWORD="$MIGRATION_DB_PASSWORD" psql --no-psqlrc -v ON_ERROR_STOP=1 -q \
            -h "$(env_value DB_HOST "$product_env")" \
            -p "$(env_value DB_PORT "$product_env")" \
            -U "$MIGRATION_DB_USERNAME" \
            -d "$(env_value DB_DATABASE "$product_env")" \
            -f ../ops/postgres/product-grants.sql
        "$PHP" artisan optimize --no-ansi
    )
done

# The platform's own catalogs and each product's (roles and permissions):
# its own application's, and those of applications it hosts (pharmacy-app
# serves retail too, platform/retail.json).
catalogs=(database/catalogs/*.json)
for code in $PRODUCTS; do
    catalogs+=("../$code-app/platform/"*.json)
done
"$PHP" artisan platform:catalog-import "${catalogs[@]}" --no-ansi
# The ERP products sold on the site and the applications they are made of.
"$PHP" artisan platform:erp-products --no-ansi
"$PHP" artisan optimize --no-ansi

# Atomic switch.
ln -sfn "$release" "$BASE/current.tmp"
mv -T "$BASE/current.tmp" "$BASE/current"

sudo systemctl reload "$PHP_FPM_SERVICE"
"$PHP" "$BASE/current/central-app/artisan" queue:restart --no-ansi
for code in $PRODUCTS; do
    "$PHP" "$BASE/current/$code-app/artisan" queue:restart --no-ansi
done
# systemd restarts the SSR server from the new release.
"$PHP" "$BASE/current/central-app/artisan" inertia:stop-ssr --no-ansi || true

if ! curl --fail --silent --show-error --max-time 10 "$HEALTH_URL" > /dev/null; then
    echo "health check failed: run ops/deploy/rollback.sh" >&2
    exit 1
fi

# Keep the newest $KEEP releases (ids sort by time; current is the newest).
ls -1d "$BASE"/releases/* | sort | head -n -"$KEEP" | xargs -r rm -rf

echo "released $id ($(cat "$release/REVISION"))"
