# Local staging rehearsal (Windows + XAMPP)

Runs the production deploy on one Windows machine before a real staging server exists. Same
gates, same order, same configuration as [docs/runbooks/deploy.md](../../docs/runbooks/deploy.md);
only the process manager and web server differ.

| Linux staging                  | Here                                                                  |
| ------------------------------ | --------------------------------------------------------------------- |
| nginx → php-fpm                | XAMPP Apache (`apache-staging.conf`) → php-cgi pool on :9201–9206     |
| Let's Encrypt wildcard         | Local CA in `D:\srv\tls`, wildcard for `*.staging.milesoftwares.com` |
| DNS                            | hosts-file entries → 127.0.0.1                                        |
| `deploy.sh` / `rollback.sh`    | `deploy.ps1` / `rollback.ps1`                                         |
| systemd units                  | `services.ps1` supervisors (restart on exit)                          |
| S3 with Object Lock            | SeaweedFS S3 on :8333, bucket `miles-staging-audit` (compliance, 1 d) |
| PostgreSQL roles, own database | Same roles, database `miles_staging` (development DB untouched)       |

## Layout

```
D:\srv\miles\repo          bare clone of this repository
D:\srv\miles\releases\     one checkout per release
D:\srv\miles\shared\.env   staging environment (hard-linked into each release)
D:\srv\miles\shared\php\   php.ini for php-cgi (opcache, CA bundle incl. the local CA)
D:\srv\miles\secrets\      db.env: database role passwords
D:\srv\miles\current       junction to the live release (the app is current\central-app)
D:\srv\seaweedfs\          S3 store and its credentials
D:\srv\tls\                local CA and certificate
```

## Deploy

```powershell
Get-Content D:\srv\miles\secrets\db.env | ForEach-Object { $k, $v = $_ -split '=', 2; Set-Item "env:$k" $v }
ops/local-staging/deploy.ps1 main -Bootstrap   # first time only
ops/local-staging/deploy.ps1 main
ops/local-staging/rollback.ps1
ops/local-staging/services.ps1 status
```

## Products

Pharmacy and Finance deploy with the platform (`$env:MILES_PRODUCTS`, default `pharmacy finance`;
set it to an empty string for none), as in the runbook's Products section. Once per product:

```powershell
createdb -U postgres staging_pharmacy
psql -U postgres -v db_name=staging_pharmacy -f ops/postgres/product-database.sql
New-Item -ItemType Directory D:\srv\miles\shared\products\pharmacy\storage\app, `
    D:\srv\miles\shared\products\pharmacy\storage\framework\cache, `
    D:\srv\miles\shared\products\pharmacy\storage\framework\sessions, `
    D:\srv\miles\shared\products\pharmacy\storage\framework\views, `
    D:\srv\miles\shared\products\pharmacy\storage\logs
# D:\srv\miles\shared\products\pharmacy\.env from pharmacy-app\.env.example:
#   APP_ENV=production, APP_DEBUG=false, a new APP_KEY, DB_DATABASE=staging_pharmacy,
#   DB_USERNAME=miles_app, PLATFORM_ROOT_DOMAIN=staging.milesoftwares.com and the product's
#   staging clients; Pharmacy also PLATFORM_FINANCE_EVENTS_URL=https://staging.milesoftwares.com/finance/events
```

The same for `finance` (`staging_finance`). Each product gets its own php-cgi pool (Pharmacy on
:9301–9302, Finance on :9311–9312), queue worker and scheduler from `services.ps1`, and Apache
proxies `/<code>/` to it (`apache-staging.conf`).

Not covered here: nginx's refusal of unknown hosts (XAMPP keeps its default vhosts), systemd
hardening, and anything that needs public DNS (SSL Labs, real certificates).
