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
    ```

4. Database roles once: `psql -v owner_password="'…'" -v app_password="'…'" -f ops/postgres/roles.sql`.
   `.env` uses `DB_USERNAME=miles_app`. The deploy user exports `MIGRATION_DB_USERNAME=miles_owner` and
   `MIGRATION_DB_PASSWORD`.
5. php-fpm pool `miles` listening on `/run/php/php8.4-fpm.sock`, `user = miles`. Allow the deploy user to
   run `sudo systemctl reload php8.4-fpm` without a password, and nothing else.
6. nginx: copy `ops/nginx/milessoftwares.conf`, then follow [dns-tls.md](dns-tls.md) for the certificate.
7. systemd: copy `ops/systemd/*` to `/etc/systemd/system/`, then
   `systemctl enable --now miles-scheduler.timer miles-backup.timer miles-ssr miles-queue@{1..3}`.
8. First super admin after the first deploy: `php artisan platform:staff <email> --role=super_admin`
   (the account must exist and have MFA enabled before it can open admin).
9. First-party OIDC clients: `php artisan platform:oidc-clients`, then put the printed ids/secrets in `.env`.

## Release

```bash
ops/deploy/deploy.sh v1.4.0        # any git ref; tags preferred
```

The script stops at the first failure and never switches traffic to a release that failed:

1. Checks out the ref into `releases/<UTC timestamp>`, links `.env` and `storage`.
2. `composer install --no-dev`, `npm ci`, `npm run build:ssr`.
3. `platform:preflight --skip-database`: configuration gate.
4. `migrate --force` as `miles_owner`, then `ops/postgres/grants.sql`.
5. `platform:preflight`: database gate as `miles_app` (not superuser, append-only audit, chain intact).
6. `platform:catalog-import`, `optimize`.
7. Atomic `current` symlink switch, php-fpm reload, `queue:restart`, SSR restart.
8. `GET /up` must answer 200, otherwise it tells you to roll back.

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
