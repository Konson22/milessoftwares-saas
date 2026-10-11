# Local staging rehearsal (Windows + XAMPP)

Runs the production deploy on one Windows machine before a real staging server exists. Same
gates, same order, same configuration as [docs/runbooks/deploy.md](../../docs/runbooks/deploy.md);
only the process manager and web server differ.

| Linux staging                  | Here                                                                  |
| ------------------------------ | --------------------------------------------------------------------- |
| nginx → php-fpm                | XAMPP Apache (`apache-staging.conf`) → php-cgi pool on :9201–9206     |
| Let's Encrypt wildcard         | Local CA in `D:\srv\tls`, wildcard for `*.staging.milessoftwares.com` |
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
D:\srv\miles\current       junction to the live release
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

Not covered here: nginx's refusal of unknown hosts (XAMPP keeps its default vhosts), systemd
hardening, and anything that needs public DNS (SSL Labs, real certificates).
