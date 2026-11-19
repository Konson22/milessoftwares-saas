# Tatook Procurement (`procurement-app`): first deploy (not deployed yet)

Tatook Procurement is its own application (ADR-0023):

- **Code:** `procurement`, folder `procurement-app`.
- **Address:** `{workspace}/procurement`.
- **Own** database, php-fpm pool and catalog.

**The deploy needs the owner's approval.** It creates a new, empty database. Nothing in Projects moves: Projects keeps its project-budget purchasing until the cutover in ADR-0023 §5.

It deploys like any product (`docs/runbooks/deploy.md`, Products). `deploy.sh`'s `product_dir` maps `procurement` to `procurement-app`. What is specific to it:

## Central, before the first deploy

1. **Catalog 1.0.0** is imported by the deploy. It lives in `procurement-app/platform/catalog.json`: 16 permissions, 9 roles, 4 separation-of-duties rules.
2. **Products and plans.** Edit `database/erp-products.json`, then run `php artisan platform:erp-products`.
   - Product `procurement` is `planned`. It requires `procurement` and lists `finance` as optional.
   - Set it to `pilot` only when the launch is approved.
   - `procurement-standard` exists only in `LocalPlansSeeder` and has no price. A price must be approved before a plan is public.
3. **Admin → Applications → `procurement`.**
   - Integration endpoint: `https://{internal}/procurement/platform`.
   - Sign-in client with the tenant redirect path `/procurement/auth/callback`.
   - Service client and webhook secret.
   - Copy the values into the product's `.env`.
   - Trials are per application. Switch the trial on only when approved.

## Server

1. **Database:** create `procurement` (owner `miles`). It must be PostgreSQL. The audit trail's append-only trigger and the CHECK constraints are PostgreSQL only.
2. **Configs:**
   - `ops/php-fpm/procurement.conf` goes to `/etc/php/8.4/fpm/pool.d/`.
   - `ops/nginx/products/procurement.conf` goes to `/etc/nginx/miles-products/`.
   - Reload both.
3. **`.env`:** start from `.env.example`.
   - `APP_URL` is the host only. The routes carry `/procurement`.
   - Set the `PLATFORM_*` values from step 3 above.
   - Uploaded documents are stored on the local private disk. `deploy.sh` links `storage/` to `shared/products/procurement/storage`, so include that directory in backups.
4. **Deploy and migrate.** The migrations only create tables.

## Optional: Finance

The Finance connection is off by default. Each organization turns it on in Procurement → Settings and must give a Finance expense account code.

- Matched invoices go to Finance through the outbox as `finance.bill.received` v1, with source id `supplier_invoice:{id}`.
- Nothing is sent for organizations not entitled to Finance.
- **Check after the first send:** in Finance, the bill appears under the supplier keyed `(procurement, supplier id)`.

## Checks after deploy

- `https://{workspace}/procurement` redirects to sign-in for a member of an entitled organization.
- It answers 404 for a workspace that is not entitled.
- `php artisan schedule:list` lists `platform:outbox` every minute (`routes/console.php`). It needs the product's scheduler cron.
- Smoke test in a test organization: requisition, approval, direct order, receipt, invoice.
  - The invoice should be "Matched".
  - The overview should show committed, invoiced and paid figures separately.

## Rollback

Disable the application in Central so no organization is entitled. Remove the nginx include and reload. The database can stay; it holds only Procurement's own records.

## Local development

- **Register:** `php artisan platform:local-product procurement-app --url=http://127.0.0.1:9500` (from `central-app`). It writes `procurement-app/.env`.
- **Entitle a workspace:** add `--entitle={slug}`.
- **Serve:** `php artisan serve --port=9500`. Apache proxies `/procurement/` there (`ops/local-dev/apache-dev.conf`).
- **Tests:** `composer ci:check` runs them on whatever `DB_*` is set. CI runs PostgreSQL and SQLite.
