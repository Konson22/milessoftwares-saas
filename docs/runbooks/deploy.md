# Runbook: Deploy and Roll Back

The platform runs on Ubuntu 24.04 behind nginx with php-fpm 8.4, PostgreSQL 17 and Redis 7. One host
is enough to start; the layout below also works per node behind a load balancer.

## One-time server setup

1. Packages: `nginx php8.4-fpm php8.4-{pgsql,redis,intl,zip,gd,mbstring,xml,curl,bcmath} composer nodejs(24) postgresql-client-17 gnupg`.
2. Users: `miles` (runs php-fpm pool, workers, scheduler, SSR) and `miles-backup`.
3. Layout:

    ```
    /srv/miles/repo            git clone --bare <repo>
    /srv/miles/releases/
    /srv/miles/shared/.env     0600 miles:miles
    /srv/miles/shared/storage/ app, framework/{cache,sessions,views}, logs
    /srv/miles/current         → releases/<id>: the whole repository
    ```

    The Laravel app is `central-app/` inside each release. Every `php artisan …` in the runbooks runs
    from `/srv/miles/current/central-app`; `ops/` scripts run from the release root.

4. Database and roles once, as a superuser:

    ```bash
    createdb miles_platform
    psql -v db_name=miles_platform -v owner_password="'…'" -v app_password="'…'" \
         -v backup_password="'…'" -f ops/postgres/roles.sql
    ```

    `.env` uses `DB_USERNAME=miles_app`. The deploy user exports `MIGRATION_DB_USERNAME=miles_owner` and
    `MIGRATION_DB_PASSWORD`; `miles_backup` goes into `/etc/miles/backup.env`
    ([backup-restore.md](backup-restore.md)).

5. php-fpm pool `miles` listening on `/run/php/php8.4-fpm.sock`, `user = miles`. Allow the deploy user to
   run `sudo systemctl reload php8.4-fpm` without a password, and nothing else.
6. nginx: copy `ops/nginx/milessoftwares.conf` (for another root domain, replace `milessoftwares.com`
   throughout), then follow [dns-tls.md](dns-tls.md) for the certificate.
7. First deploy: `ops/deploy/deploy.sh <ref> --bootstrap`. It migrates and creates the first-party OIDC
   clients (written into `shared/.env`) before the configuration gate, which refuses to pass without them.
   Later deploys never pass `--bootstrap`. Set `HEALTH_URL` for any environment other than production.
8. systemd: copy `ops/systemd/*` to `/etc/systemd/system/`, then
   `systemctl enable --now miles-scheduler.timer miles-backup.timer miles-ssr miles-queue@{1..3}`.
9. First super admin: `php artisan platform:staff <email> --role=super_admin`
   (the account must exist and have MFA enabled before it can open admin).

## Products

Products (Pharmacy, Finance, …) ship in the same release as the platform: `releases/<id>/<code>-app`.
`deploy.sh` deploys the ones in `PRODUCTS` (default `pharmacy finance`; `PRODUCTS=""` for none).
Once per product, before its first deploy:

1. Database, as a superuser (the roles from `roles.sql` are reused):

    ```bash
    createdb pharmacy
    psql -v db_name=pharmacy -f ops/postgres/product-database.sql
    ```

2. `/srv/miles/shared/products/<code>/.env` (0600 miles:miles) from `<code>-app/.env.example`:
   `APP_ENV=production`, `APP_DEBUG=false`, a new `APP_KEY`, `DB_DATABASE=<code>`,
   `DB_USERNAME=miles_app`, and the `PLATFORM_*` values. Register the product on the platform
   (admin → Applications, or `php artisan platform:local-product` on a staging copy) for its
   sign-in and service clients and webhook secret. Products that send business events set
   `PLATFORM_FINANCE_EVENTS_URL=https://milessoftwares.com/finance/events` (any host the nginx
   server block answers; the event token, not the host, proves who sends what).
3. `/srv/miles/shared/products/<code>/storage/` with `app`, `framework/{cache,sessions,views}`, `logs`.
4. php-fpm pool: copy `ops/php-fpm/<code>.conf` to `/etc/php/8.4/fpm/pool.d/`.
5. nginx: copy `ops/nginx/products/<code>.conf` to `/etc/nginx/miles-products/`, `nginx -t`, reload.
6. After its first deploy: `systemctl enable --now miles-product-queue@<code> miles-product-scheduler@<code>.timer`
   (the scheduler delivers the product's outbox of business events every minute).

Retail is not a product folder of its own: pharmacy-app serves it at `/retail` (ADR-0003). Its
setup is part of pharmacy's: `PLATFORM_HOSTED=retail` and the `PLATFORM_RETAIL_*` client values in
the pharmacy `.env` (register Retail & POS in admin like any application), `SESSION_PATH=/` so the
session reaches both `/pharmacy` and `/retail`, and `ops/nginx/products/retail.conf` next to
`pharmacy.conf`. Its catalog, `pharmacy-app/platform/retail.json`, is imported with pharmacy's.

Each release then installs and builds every product, migrates its database as `miles_owner` and runs
`ops/postgres/product-grants.sql`, caches its configuration, imports its `platform/*.json` catalogs
into the platform, and restarts its queue worker after the switch. Product migrations follow the same
expand → deploy → contract rule as the platform's.

## Release

```bash
ops/deploy/deploy.sh v1.4.0        # any git ref; tags preferred
```

The script stops at the first failure and never switches traffic to a release that failed:

1. Fetches every branch and tag (the bare clone has no fetch refspec of its own), checks out the ref
   into `releases/<UTC timestamp>`, links `.env` and `storage`.
2. `composer install --no-dev`, `npm ci`, `npm run build:ssr`; for each product, `composer install
   --no-dev`, `npm ci`, `npm run build`.
3. `platform:preflight --skip-database`: configuration gate.
4. `migrate --force` as `miles_owner`, then `ops/postgres/grants.sql`.
5. `platform:preflight`: database gate as `miles_app` (not superuser, append-only audit, chain intact).
6. For each product: `migrate --force` as `miles_owner`, `ops/postgres/product-grants.sql`, `optimize`.
7. `platform:catalog-import` (the platform's catalogs and each product's), `optimize`.
8. Atomic `current` symlink switch, php-fpm reload, `queue:restart` (platform and products), SSR restart.
9. `GET /up` must answer 200, otherwise it tells you to roll back.

**Migrations must be backward compatible** with the release before (expand → deploy → contract):
add columns nullable or with defaults, backfill in a job, drop old columns one release later.
Rollback never reverses a migration.

## Roll back

```bash
ops/deploy/rollback.sh
```

Points `current` at the newest release older than the current one, reloads php-fpm, restarts workers
and SSR. Then run `curl -fsS https://milessoftwares.com/up` and check the logs. If the bad release
wrote data the old code cannot read, restore is a data incident: see [incidents.md](incidents.md).

## Maintenance mode

`php artisan down --secret=<random>` serves 503 everywhere except to browsers that visited
`/<random>` once. `php artisan up` ends it. Use only for migrations that cannot be made backward
compatible.

## After every release

- `php artisan queue:failed` is empty, or new failures are understood.
- Error rate and p95 latency on the dashboard are back to the usual level within 10 minutes.
- Record the release id and git revision (`cat /srv/miles/current/REVISION`) in the change log.
