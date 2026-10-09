# Implementation Progress

## Environment (2026-10-09)

| Item                           | State                                                                              |
| ------------------------------ | ---------------------------------------------------------------------------------- |
| OS                             | Windows 11; Git Bash + PowerShell                                                  |
| PHP                            | 8.4.26 at `C:\php84` (XAMPP 8.2.12 still installed, unused)                        |
| PHP extensions (XAMPP php.ini) | enabled pdo_pgsql, pgsql, intl, zip, sodium, gd (backup: `php.ini.bak-2026-10-09`) |
| Composer / Node / npm          | 2.9.7 / 24.15.0 / 11.12.1                                                          |
| PostgreSQL                     | 17.11 native; DBs `miles_platform`, `miles_platform_test`                          |
| Redis                          | Memurai 4.1.2 (Redis-compatible), port 6379                                        |
| Docker / WSL                   | not installed (not required)                                                       |

## Decisions

- Laravel 13 on PHP 8.4 (user decision 2026-10-09).
- PostgreSQL + Redis installed natively for local dev; CI uses Ubuntu service containers.
- Modular monolith under `app/Modules/*`; architecture in `docs/architecture.md`.
- OIDC provider: Passport 13 + jeremy379/laravel-openid-connect, hardened per `docs/adr/0002-oidc-provider.md`.
- One role assignment = one scope (folds `role_assignment_scopes` into `role_assignments`).
- Dev root domain: `.env.example` uses `milessoftwares.localhost`; this machine uses `milessoftwares.local`
  on port 8100 (hosts-file entries per subdomain, server bound to 0.0.0.0) — user decision 2026-10-09.
- Session revocation is driver-agnostic: a `user_sessions` row id is stored in the session payload and
  checked on every request, so Redis sessions stay. Revoking rotates the remember-me token.

## Phase status

| Phase                 | Status                                                           |
| --------------------- | ---------------------------------------------------------------- |
| 0 Architecture & plan | Done — `docs/architecture.md`                                    |
| 1 Foundation          | Done (2026-10-09) — committed `09f7c45`                          |
| 2 Central Auth        | Done (2026-10-10) — committed `09f7c45`                          |
| 3 Orgs & tenancy      | Done (2026-10-10) — committed `09f7c45`                          |
| 4 Structure & RBAC    | Done (2026-10-10) — branch `phase-4-structure-rbac`, uncommitted |
| 5–10                  | Not started                                                      |

## Verification log

_(test, migration and build results are recorded here per phase — actual output only)_

### Phase 1 — 2026-10-09 (local, Windows)

- `php artisan migrate:fresh` on PostgreSQL 17.11: all 15 migrations DONE.
- `composer ci:check`: `vp check` pass (76 files formatted, 0 lint issues); `tsc --noEmit` clean;
  Vitest 4 tests passed; Pint passed; PHPStan 0 errors; Pest 151 passed (337 assertions).
- `npm run build`: built in 4.1s.
- CI workflow (`.github/workflows/tests.yml`) updated to PHP 8.4 / Node 24 with PostgreSQL 17 and
  Redis 7 service containers; not yet run on GitHub (no remote/commit).

### Phase 2 (part 1) — 2026-10-09 (local, Windows)

- Added Identity module: authentication_events audit (login, failures with HMAC-hashed identifier,
  lockout, logout, MFA, recovery codes, passkeys), `user_sessions` tracking + sessions settings page,
  queued security-alert emails, suspended/deactivated accounts rejected at sign-in and signed out.
- `composer ci:check`: vp check pass (77 files); tsc clean; Vitest 4 passed; Pint passed; PHPStan 0
  errors; Pest 172 passed (412 assertions).

### Phase 2 (part 2) — 2026-10-10 (local, Windows)

- OpenID Provider on the auth host: discovery, JWKS (kid = RFC 7638 thumbprint), authorize, token.
  PKCE S256 + state + nonce mandatory; exact redirect URIs incl. registry per-tenant rows; consent for
  third-party clients only. ADR-0002 written.
- `composer ci:check`: vp check pass (78 files); tsc clean; Vitest 4 passed; Pint passed; PHPStan 0
  errors; Pest 192 passed (495 assertions). `npm run build` succeeded.

### Phase 2 (part 3) — 2026-10-10 (local, Windows)

- app. and admin. are confidential OIDC clients (`platform:oidc-clients --write-env`): code + PKCE +
  state + nonce, id_token signature/iss/aud/exp/nonce verified, host-only sessions. Local logout then an
  optional central sign-out page on auth. Inertia-safe logout responses across hosts.
- Local back-channel: `composer dev` also serves 127.0.0.1:8101 for the token endpoint
  (`PLATFORM_OIDC_BACKCHANNEL_URL`), because `php artisan serve` on Windows cannot call itself.
- `composer ci:check`: all pass; Pest 209 passed (641 assertions); PHPStan 0 errors.
- Live smoke test on milessoftwares.local:8100 with curl: app login → auth sign-in → code → token via
  8101 → app dashboard 200; app logout → auth signout page 200 → app dashboard redirects to sign-in.
  Throwaway user deleted afterwards.

### Phase 3 — 2026-10-10 (local, Windows)

- Create organization on app. (one transaction; `pending` until owner membership, slug and workspace
  callback exist), live slug availability, blocklist of brand/abuse terms, slug rename with 180-day
  redirect, 30-day cooldown and permanent retirement. Owner-only settings until Phase 4 roles.
- Current organization on app. kept in session and re-validated against the membership on every
  request; binds TenantContext. Switching to a non-member or inactive membership returns 404.
- Workspace hosts sign in through auth. with one shared workspace client and one exact callback row per
  organization (`application_client_redirect_uris`); only the requested URI is looked up.
- Workspace members pages; cross-tenant known-ID tests (foreign id = unknown id = 404).
- `composer ci:check`: all pass; Pest 250 passed (808 assertions); PHPStan 0 errors; build OK.
  Mutation check: removing the active-membership filter fails the switching tests.
- Live smoke on :8100 with curl: app sign-in → create `smoke-shop` → workspace SSO → home/members 200.
  Smoke org and user deleted afterwards; its `organization.created` audit row remains (append-only).

### Phase 4 — 2026-10-10 (local, Windows)

- Catalog import (`platform:catalog-import`, `database/catalogs/*.json`): immutable versions with a
  content checksum; identical re-import is a no-op, changed content is refused, a new version retires
  the previous one. The platform itself is a catalogued application (`platform.access.manage`,
  `platform.structure.manage`, `platform.members.view`).
- Scope engine: organization ⊇ branch ⊇ its departments ⊇ sub-departments; a resource contains only
  itself; department cycles cannot loop.
- AssignRole / RevokeRole enforce delegation (access.manage at a containing scope, delegable role,
  sensitivity no higher than the grantor's own in that application, entitlement, no self-elevation;
  owners bounded by entitlement only) and separation of duties (block / warn at overlapping scopes;
  warn needs acknowledgement). Every change is audited.
- Workspace pages: structure (branches, departments, positions; archive instead of delete) and access.
  Navigation shows them only to members who can use them.
- `composer ci:check`: all pass; Pest 315 passed (977 assertions); PHPStan 0 errors; build OK.
  Mutation checks: removing the sensitivity, self-elevation or grantor-scope check each fails the
  matching delegation tests.
- Live smoke on :8100: org created, workspace SSO, structure page 200, branch POST redirects back,
  access page 200. Smoke org and user deleted.
