# Security Review (Phase 10)

Date: 2026-10-10. Scope: the platform repository at branch `phase-10-production` (auth, app, admin,
workspace and apex hosts, product API, shared-module contracts, ops scripts). Method: requirement-by-
requirement walk of README §20 against code and tests, a sweep of every registered route, dependency
audits, and mutation checks (break the control, confirm a test fails). External penetration testing
is still required before go-live (see "Open items").

## Requirements (README §20)

| Requirement                     | Control                                                                                                                                        | Evidence (tests)                                                         |
| ------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------ |
| Trusted hostname validation     | `trustHosts` (root + first-level subdomains); nginx drops other hosts; `PlatformHosts::classify`                                               | `Routing/HostRoutingTest`                                                |
| Secure tenant resolution        | `ResolveTenant` before auth; unknown, reserved, malformed, pending, archived slugs → 404; retired slugs redirect only in grace period          | `Tenancy/TenantResolutionTest`                                           |
| Server-side authorization       | `workspace.access`, delegation rules in `AssignRole`, `staff.can:*`, API scopes                                                                | `Authorization/*`, `Admin/*`, `Integration/*`                            |
| Cross-tenant tests              | Fail-closed `TenantScope`, composite FKs, known-ID attacks, **route sweep** of every workspace route with another organization's real ids      | `Tenancy/*`, `Tenancy/RouteSweepTest` (new)                              |
| CSRF                            | Laravel CSRF on all web routes; the API takes bearer tokens only                                                                               | framework                                                                |
| XSS                             | React escaping; CSP with per-request nonce, no `unsafe-inline`/`unsafe-eval` scripts; four `dangerouslySetInnerHTML` sinks reviewed (below)    | `Security/SecurityHeadersTest`                                           |
| SQL injection                   | Eloquent/query builder bindings; raw SQL only with bound parameters or constants                                                               | review                                                                   |
| Rate limiting                   | sign-in 5/min per email+IP, 2FA, passkeys; lead forms 5/h per IP; invitations; API 600/min per source IP                                       | `Auth/*`, `Marketing/*`                                                  |
| Secure cookies                  | Host-only cookies, `__Host-` prefix, Secure, HttpOnly, SameSite=lax: enforced by `platform:preflight`                                          | `Operations/PreflightTest` (new)                                         |
| Secret management               | Secrets only in `.env`/secret store; OIDC/API secrets shown once and stored hashed; migration credentials outside `.env`                       | review, `Integration/ClientRegistrationTest`                             |
| MFA                             | TOTP + recovery codes + passkeys; required for platform staff                                                                                  | `Admin/AdminAccessTest`                                                  |
| Scoped access tokens            | 10-minute access tokens; client-credentials scopes per endpoint; event tokens audience-bound, 5-minute TTL                                     | `Integration/*`, `SharedModules/*`                                       |
| Session invalidation            | `user_sessions` checked per request; revocation rotates remember-me; **`platform:user-status` also revokes product access and refresh tokens** | `Identity/SessionManagementTest`, `Identity/UserStatusCommandTest` (new) |
| Privileged action auditing      | Hash-chained, append-only `audit_logs` (trigger + DB grants), hourly off-site export, daily verification                                       | `Audit/*`, `Operations/PreflightTest`                                    |
| Horizontal privilege escalation | Route sweep + known-ID tests; no self-elevation; owner/last-super-admin protections                                                            | `Tenancy/*`, `Authorization/DelegationTest`, `Admin/StaffManagementTest` |
| Mass assignment                 | `organization_id` always guarded (never fillable); tenant moves throw `CrossTenantWrite`                                                       | `Tenancy/TenantIsolationTest`                                            |
| Strict CORS and redirects       | **No CORS headers on any path** (`config/cors.php`); OIDC redirect URIs matched exactly, per-tenant rows only                                  | `Security/SecurityHeadersTest`, `Identity/OpenIdProviderTest`            |

## Findings fixed in this phase

| #   | Severity | Finding                                                                                                                                     | Fix                                                                                                                                                    |
| --- | -------- | ------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 1   | Medium   | No browser hardening headers (CSP, frame denial, HSTS, nosniff)                                                                             | `SecurityHeaders` middleware, nonce-based CSP, HSTS behind a flag                                                                                      |
| 2   | Medium   | Suspending an account cut its platform sessions but left product access tokens and 30-day refresh tokens valid                              | `platform:user-status` revokes both and audits the change                                                                                              |
| 3   | Medium   | `backup.sh` left an unencrypted database dump on disk when GPG encryption failed (reproduced locally)                                       | EXIT trap removes plaintext and partial files; umask 077; production check before dumping                                                              |
| 4   | Low      | Framework default CORS answered `Access-Control-Allow-Origin: *` on `api/*`                                                                 | `config/cors.php` with no paths                                                                                                                        |
| 5   | Low      | Audit trail only in the primary database; a database superuser could rewrite it undetected until the next manual check                      | Hourly NDJSON export with hashes to write-once storage, daily `platform:audit-verify` with a critical alert, preflight requires an off-site disk       |
| 6   | Low      | Application could run as the schema owner (full rights on `audit_logs`)                                                                     | Separate `miles_owner`/`miles_app`/`miles_backup` roles; preflight fails if the runtime role can UPDATE/DELETE/TRUNCATE `audit_logs` or is a superuser |
| 7   | Low      | Client IP and HTTPS detection behind a load balancer unconfigured                                                                           | `TRUSTED_PROXIES` (explicit list; preflight warns on `*`)                                                                                              |
| 8   | Low      | Critical advisories in dev tooling (`tinypool` via `vite-plus`), installed on servers by `npm ci` during deploy                             | npm `overrides` pins `tinypool` 2.1.2 (patched); `vite-plus` 0.3.3 would need a toolchain migration (`vp migrate`)                                     |
| 9   | Info     | `deploy.sh` would fail on a fresh database (preflight before migrations); nginx enabled OCSP stapling that Let's Encrypt no longer supports | Preflight split around migrations; stapling removed                                                                                                    |

## Reviewed and accepted

- `dangerouslySetInnerHTML` in `seo.tsx` (server-built JSON-LD, `<` escaped), `two-factor-setup-modal.tsx`
  (server-generated QR SVG), and pagination labels in two admin pages (Laravel's own translation
  strings, never user input).
- `style-src 'unsafe-inline'`: UI components set style attributes; scripts stay nonce-only.
- Marketing forms answer spam and real submissions identically (no oracle for bots).

## Open items (before go-live)

1. External penetration test of staging (auth flows, tenant isolation, admin support access).
2. Load test on staging with the k6 script; record results in `docs/PROGRESS.md`.
3. Audit retention purge (7 years) is not implemented. When it is, verification must start from the
   oldest kept row's `prev_hash` (anchored on the export) instead of null.
4. PostgreSQL row-level security as defence in depth (architecture risk table) was not added: the
   fail-closed global scope, composite foreign keys and the route sweep cover the same risk in the
   application. Revisit if raw SQL access grows.
5. The successful GPG encryption path of `backup.sh` was not run on the Windows development machine
   (gpg-agent socket path limit); run the backup and restore drill on staging.
