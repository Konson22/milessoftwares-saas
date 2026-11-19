# Tatook Logbook (`logbook-app`): first deploy (not deployed yet)

Tatook Logbook is its own application (ADR-0025):

- **Code:** `logbook`, folder `logbook-app`.
- **Address:** `{workspace}/logbook`.
- **Own** database, php-fpm pool and catalog.

**The deploy needs the owner's approval.** It creates a new, empty database and touches no other product.

It deploys like any product (`docs/runbooks/deploy.md`, Products). `deploy.sh`'s `product_dir` maps `logbook` to `logbook-app`; add `logbook` to the server's `PRODUCTS` list. What is specific to it:

## Central, before the first deploy

1. **Catalog 1.0.0** is imported by the deploy. It lives in `logbook-app/platform/catalog.json`: 6 permissions, 6 roles, 1 separation-of-duties warning (writing and reviewing entries).
2. **Products and plans.** `database/erp-products.json` has product `logbook`, `pilot` (shown as Early access), requiring `logbook`. Run `php artisan platform:erp-products`; it stays planned until the application is registered.
   - `logbook-standard` is USD 15.00 a month (approved 2026-11-19). It exists only in `LocalPlansSeeder`: create the same plan in Central admin on production.
3. **Admin → Applications → `logbook`.**
   - Integration endpoint: `https://{internal}/logbook/platform`.
   - Sign-in client with the tenant redirect path `/logbook/auth/callback`.
   - Service client and webhook secret. Copy the values into the product's `.env`.
   - Mobile client (public, PKCE) with redirect `com.tatook.logbook://oauth`, for the phone app. Its client ID goes in `PLATFORM_MOBILE_CLIENT_ID` and in the phone build's `EXPO_PUBLIC_CLIENT_ID`.
   - Trials are per application. Switch the trial on only when approved.

## Server

1. **Database:** create `logbook` (owner `miles`). It must be PostgreSQL: append-only audit events, entry revisions and reviews, and immutable published template versions are enforced by triggers, with CHECK constraints and partial unique indexes.
2. **Configs:**
   - `ops/php-fpm/logbook.conf` goes to `/etc/php/8.4/fpm/pool.d/`.
   - `ops/nginx/products/logbook.conf` goes to `/etc/nginx/miles-products/`.
   - Reload both.
3. **`.env`:** start from `.env.example`.
   - `APP_URL` is the host only. The routes carry `/logbook`.
   - Set the `PLATFORM_*` values from step 3 above.
   - Attachments (10 MB each) are stored on the local private disk under `storage/app/private/logbook/{organization}/{entry}`. `deploy.sh` links `storage/` to `shared/products/logbook/storage`, so include that directory in backups.
4. **Deploy and migrate.** The migrations only create tables, triggers and indexes, and add the nullable `sha256` column to attachments.

## Checks after deploy

- `https://{workspace}/logbook` redirects to sign-in for a member of an entitled organization; it answers 404 for a workspace that is not entitled.
- Smoke test in a test organization:
  - Create a logbook, add a sheet from the Generator starter template, publish it.
  - A contributor writes and submits an entry: it gets `GEN-{year}-000001`.
  - A second person reviews it (the author cannot). Return one for correction, resubmit, then mark reviewed.
  - Reports: number totals for the sheet, CSV and PDF exports open.
  - Audit trail lists every step.

## Rollback

Disable the application in Central so no organization is entitled. Remove the nginx include and reload. The database can stay; it holds only Logbook's own records.

## Local development

- **Register:** `php artisan platform:local-product logbook-app --url=http://127.0.0.1:9600 --mobile-redirect=com.tatook.logbook://oauth` (from `central-app`). It writes `logbook-app/.env`, including `PLATFORM_MOBILE_CLIENT_ID` for the phone app (`logbook-mobile-app`).
- **Entitle a workspace:** add `--entitle={slug}`.
- **Serve:** `php artisan serve --port=9600`. Apache proxies `/logbook/` there (`ops/local-dev/apache-dev.conf`; copy it to the live Apache include and restart Apache).
- **Tests:** `composer ci:check` runs them on whatever `DB_*` is set (`phpunit.xml` defaults to SQLite, which skips the trigger tests). For PostgreSQL: `DB_CONNECTION=pgsql DB_DATABASE=logbook_test php artisan test`. CI runs both.
