# Production Readiness Checklist

Release gate for the first production launch and for every new environment. Items marked
**(auto)** are enforced by `php artisan platform:preflight`, which `ops/deploy/deploy.sh` runs before
migrating and again before switching traffic. Everything else needs a named person to check it and
sign it off in the table at the end.

Runbooks: [deploy](runbooks/deploy.md) · [backup & restore](runbooks/backup-restore.md) ·
[DNS & TLS](runbooks/dns-tls.md) · [monitoring](runbooks/monitoring.md) ·
[incidents](runbooks/incidents.md). Security review: [security-review.md](security-review.md).

## 1. Application configuration

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` set, `APP_URL=https://milesoftwares.com` **(auto)**
- [ ] `PLATFORM_ROOT_DOMAIN=milesoftwares.com` (not a `.local`/`.localhost`/`.test` name) **(auto)**
- [ ] `LOG_LEVEL` is `info` or higher (preflight warns on `debug`)
- [ ] `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, `SESSION_SAME_SITE=lax`, `SESSION_DOMAIN` empty **(auto)**
- [ ] `SESSION_COOKIE=__Host-miles-session` (host-only, Secure, path `/`) **(auto)**
- [ ] `SESSION_ENCRYPT=true` (preflight warns otherwise)
- [ ] `SESSION_DRIVER=redis`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis` **(auto)**
- [ ] `MAIL_MAILER` is a real transport (production: `resend` with `RESEND_API_KEY`; SMTP/SES also work), sender domain has SPF, DKIM and DMARC **(auto for the mailer)**
- [ ] `TRUSTED_PROXIES` lists the load balancer addresses explicitly, never `*` (preflight warns)
- [ ] `PLATFORM_OIDC_BACKCHANNEL_URL` is empty (the local-only Windows workaround)
- [ ] First-party OIDC clients for app, admin and workspace are set **(auto)**
- [ ] `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY` come from the secret store, not from files in a release **(auto: key present)**
- [ ] `PLATFORM_CSP=true`, `PLATFORM_CSP_REPORT_ONLY=false` **(auto)**
- [ ] `PLATFORM_HSTS=true` once every host serves a valid certificate **(auto)**
- [ ] `PLATFORM_SALES_EMAIL` reaches the sales inbox

## 2. Database

- [ ] PostgreSQL 17 with TLS from the application hosts; no public listener
- [ ] Roles created with `ops/postgres/roles.sql`; the application connects as `miles_app`, migrations run as `miles_owner`
- [ ] `miles_app` is not a superuser **(auto)**
- [ ] `miles_app` cannot UPDATE, DELETE or TRUNCATE `audit_logs` (`ops/postgres/grants.sql`) **(auto)**
- [ ] Audit hash chain intact **(auto)**
- [ ] WAL archiving / point-in-time recovery enabled on the server
- [ ] `php artisan migrate:status` shows every migration ran

## 3. Secrets

- [ ] `.env` lives only in `/srv/miles/shared/.env`, mode 0600, owned by the `miles` user
- [ ] `MIGRATION_DB_*` only in the deploy user's environment, never in `.env`
- [ ] Backup GPG private key is kept offline (two holders); only the public key is on servers
- [ ] OIDC client secrets, webhook secrets and API secrets were generated in production, never copied from staging

## 4. Network, DNS and TLS — see [dns-tls.md](runbooks/dns-tls.md)

- [ ] `A`/`AAAA` for `milesoftwares.com` and `*.milesoftwares.com` point at the load balancer
- [ ] Wildcard certificate covers apex and `*.milesoftwares.com`, renews automatically (DNS-01)
- [ ] `https://` works for apex, `www`, `auth`, `app`, `admin` and one real workspace; `http://` redirects
- [ ] An unknown host (`curl -H 'Host: evil.example'`) is refused by nginx
- [ ] An unknown workspace (`nope-xyz.milesoftwares.com`) answers 404
- [ ] `/robots.txt` on a signed-in host disallows everything
- [ ] SSL Labs grade A or better; `securityheaders.com` grade A or better

## 5. Processes — see [deploy.md](runbooks/deploy.md)

- [ ] php-fpm, nginx, `miles-queue@{1..3}`, `miles-scheduler.timer`, `miles-ssr`, `miles-backup.timer` enabled
- [ ] `php artisan schedule:list` shows invitations, webhooks, audit export/verify and `queue:monitor`
- [ ] A test deploy and a rollback have both been run on staging

## 6. Tenant isolation

- [ ] CI green, including `tests/Feature/Tenancy` (route sweep, known-ID attacks, fail-closed scope)
- [ ] Manual check on staging with two organizations: a member of A cannot open B's workspace, and B's
      record ids on A's host answer 404
- [ ] Admin support access to customer data needs an approved, unexpired grant

## 7. Load — targets for `ops/load/platform.js` on staging

| Scenario         | Rate      | Target                  |
| ---------------- | --------- | ----------------------- |
| Marketing pages  | 30 req/s  | p95 < 500 ms            |
| Effective access | 100 req/s | p95 < 150 ms, p99 < 400 |
| Event tokens     | 10 req/s  | p95 < 300 ms            |
| Sign-in page     | 5 req/s   | p95 < 500 ms            |
| All              |           | errors < 1 %            |

- [ ] k6 run on staging (production-sized instance) meets every threshold; result attached below
- [ ] Lighthouse on staging over HTTPS: performance ≥ 90, accessibility 100, best practices ≥ 95, SEO 100

## 8. Queues and retries

- [ ] Workers restart on deploy (`queue:restart`) and recover from a killed worker (systemd)
- [ ] A webhook to an endpoint returning 500 is retried 1 m / 5 m / 30 m / 2 h / 6 h, then marked failed
      and can be redelivered from admin (covered in CI; repeat once on staging)
- [ ] `queue:monitor` alert fires when the backlog exceeds `QUEUE_MONITOR_MAX`
- [ ] `php artisan queue:failed` is empty after the staging run

## 9. Backups — see [backup-restore.md](runbooks/backup-restore.md)

- [ ] Nightly encrypted dump uploaded off-site, retention set on the bucket
- [ ] Audit export (`platform:audit-export`) writes to write-once storage (S3 Object Lock or equivalent)
- [ ] Restore drill done on staging: dump restored into a new database, `restore.sh` verification passed,
      time to restore recorded
- [ ] Point-in-time recovery drill done once

## 10. Monitoring — see [monitoring.md](runbooks/monitoring.md)

- [ ] Uptime check on `https://milesoftwares.com/up` and `https://auth.milesoftwares.com/up`
- [ ] Log alerts on `critical` and on `Queued job failed`
- [ ] On-call rota and escalation contacts filled in [incidents.md](runbooks/incidents.md)

## Sign-off

| Area              | Checked by | Date | Notes |
| ----------------- | ---------- | ---- | ----- |
| Configuration     |            |      |       |
| Database          |            |      |       |
| Secrets           |            |      |       |
| DNS and TLS       |            |      |       |
| Processes         |            |      |       |
| Tenant isolation  |            |      |       |
| Load              |            |      |       |
| Queues            |            |      |       |
| Backups           |            |      |       |
| Monitoring        |            |      |       |
| Security review   |            |      |       |
| **Go-live owner** |            |      |       |
