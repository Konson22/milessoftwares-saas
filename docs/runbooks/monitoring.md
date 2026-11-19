# Runbook: Monitoring and Alerts

The application writes structured log lines; the log shipper (journald or file → the log platform)
turns the ones below into alerts. Keep `LOG_LEVEL=info` or higher in production.

## Health

`GET /up` on any platform host answers 200 only when PostgreSQL and the cache (Redis) respond
(`OperationsServiceProvider`). It answers 500 otherwise, so the load balancer takes the node out.

| Check                                | Interval | Alert when                |
| ------------------------------------ | -------- | ------------------------- |
| `https://milessoftwares.com/up`      | 1 min    | 2 failures in a row: page |
| `https://auth.milessoftwares.com/up` | 1 min    | 2 failures in a row: page |
| Certificate expiry                   | daily    | < 14 days: ticket         |

## Alerts from logs

| Log line (level)                              | Source                                | Meaning / first step                                                        |
| --------------------------------------------- | ------------------------------------- | --------------------------------------------------------------------------- |
| `Audit hash chain broken` (critical)          | `platform:audit-verify` 03:30         | Audit rows changed outside the app. **Security incident**, see incidents.md |
| `Queue backlog over threshold` (critical)     | `queue:monitor` every minute          | Workers down or too slow: `systemctl status 'miles-queue@*'`                |
| `Queued job failed` (error)                   | every failed job                      | `php artisan queue:failed`; webhooks retry themselves                       |
| HTTP 5xx rate > 1 % for 5 min                 | nginx access log                      | Recent deploy? Roll back first, then investigate                            |
| `authentication_events` with `lockout` spikes | Fortify throttle (5/min per email+IP) | Credential stuffing; check source IPs, consider WAF rules                   |

## Dashboards

- Requests: rate, 5xx rate, p95 latency per host type (apex, auth, app, admin, workspace, `api/v1`).
- Queue: size of `queues:default`, failed jobs per hour.
- Database: connections, slow queries (> 500 ms), replication lag, disk.
- Redis: memory, evictions (must stay 0; sessions live there).
- Business: webhook deliveries failed, provisioning steps failed, shared-module health (admin overview
  shows modules reporting `degraded`, or `unknown` after 5 minutes of silence).

## Scheduled jobs to watch

| Command                       | Schedule        | Silent failure check                        |
| ----------------------------- | --------------- | ------------------------------------------- |
| `platform:expire-invitations` | hourly          | —                                           |
| `platform:retry-webhooks`     | every 5 minutes | deliveries stuck in `pending` > 7 h         |
| `platform:audit-export`       | hourly          | newest `audit_exports.created_at` > 2 h old |
| `platform:audit-verify`       | daily 03:30     | no "Audit chain intact" line in 26 h        |
| `queue:monitor`               | every minute    | —                                           |
| `backup.sh`                   | daily 02:15 UTC | no new object in the backup bucket in 26 h  |

`miles-scheduler.timer` runs `schedule:run` every minute; `systemctl list-timers` shows the last run.

## CSP reports

Set `PLATFORM_CSP_REPORT_URI` to a report collector to see blocked resources. A new legitimate
source (e.g. an analytics script) needs a code change to `SecurityHeaders::policy()`; never switch
the policy to report-only in production to make something work.
