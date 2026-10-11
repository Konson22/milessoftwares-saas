# Runbook: Incidents

## Contacts

| Role                    | Name | Phone | Backup |
| ----------------------- | ---- | ----- | ------ |
| On-call engineer        |      |       |        |
| Incident lead           |      |       |        |
| Security contact        |      |       |        |
| Customer comms          |      |       |        |
| Miles Global Link legal |      |       |        |

_Fill in before go-live (production checklist §10)._

## Severity

| Level | Examples                                                                       | Response            |
| ----- | ------------------------------------------------------------------------------ | ------------------- |
| SEV1  | Platform down; data of one organization visible to another; audit chain broken | Page now, all hands |
| SEV2  | Sign-in failing for some users; webhooks not delivered for > 1 h               | Within 30 min       |
| SEV3  | One product's provisioning failing; degraded performance                       | Next working hours  |

## First steps (every incident)

1. Open an incident channel and a timeline document; note times in UTC.
2. Was there a release in the last hour? Roll back first (`ops/deploy/rollback.sh`), investigate after.
3. Preserve evidence: do not delete logs, failed jobs or database rows.

## Playbooks

### Suspected cross-tenant data exposure (SEV1)

1. Identify the route and the organizations involved from access logs and `audit_logs`.
2. If a single route is to blame, block it at nginx (`location = /path { return 503; }`) or roll back.
3. Write a failing test reproducing it next to `tests/Feature/Tenancy/RouteSweepTest.php`, fix, deploy.
4. List every request that hit the route during the exposure window. Notify the affected organizations
   and legal; regulators may need notice within 72 hours.

### Audit hash chain broken (SEV1)

1. `php artisan platform:audit-verify` prints the first broken sequence.
2. Compare the database rows around it with the off-site export (`audit` disk), which is write-once.
3. Assume database credentials with more than `miles_app` rights were used: rotate `miles_owner` and the
   superuser passwords, review `pg_stat_activity`/server logs, check who has database access.
4. Never "repair" the chain in place. Record the finding; the export is the evidence copy.

### Compromised account or secret

| What leaked                 | Action                                                                                                                                                                                                             |
| --------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| A user's password / session | `php artisan platform:user-status <email> suspended --reason="…"` (signs out everywhere, revokes product access and refresh tokens, audited); reactivate with `active` after a password reset and MFA re-enrolment |
| Staff account               | Same command, plus suspend the staff record in admin, review `audit_logs` for that actor and revoke their support-access grants                                                                                    |
| Product client secret       | Admin → Applications → Integration → rotate secret (old one stops working); or disable the client                                                                                                                  |
| Webhook signing secret      | Rotate in admin; tell the product team to update their verifier                                                                                                                                                    |
| `APP_KEY`                   | New key, keep the old one in `APP_PREVIOUS_KEYS` for decrypting, everyone is signed out                                                                                                                            |
| Passport signing key        | New key pair; all access and id tokens become invalid; products re-fetch JWKS (new `kid`)                                                                                                                          |
| Database password           | Rotate the role password, update `.env`, `php artisan optimize`, reload php-fpm, `queue:restart`                                                                                                                   |

### Queue backlog / workers down (SEV2)

1. `systemctl status 'miles-queue@*'`, `journalctl -u miles-queue@1 -n 100`.
2. Redis reachable? `redis-cli ping`. Memory full? Evictions?
3. Start more workers temporarily: `systemctl start miles-queue@{4..6}`.
4. After recovery: `php artisan queue:failed`, retry with `queue:retry <uuid>` once the cause is fixed.
   Webhooks and provisioning have their own retry; do not retry those jobs by hand twice.

### Database down (SEV1)

1. `/up` answers 500, so traffic stops at the load balancer; the site shows the balancer's error page.
2. Follow the hosting provider's failover. If data is damaged: [backup-restore.md](backup-restore.md),
   "Restoring production".

## After the incident

Write a blameless review within 5 working days: timeline, impact (organizations, users, data),
root cause, what detected it, and follow-up actions with owners. Add a regression test for every
code-level cause.
