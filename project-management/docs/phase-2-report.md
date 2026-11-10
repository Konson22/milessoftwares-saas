# Phase 2 report: standalone foundation

Date: 2026-11-03. Follows `docs/phase-1-audit.md`.

## Summary

The app no longer needs the central platform for anything. It has its own accounts (Laravel
Fortify, Argon2id), organizations at `/o/{organization}`, memberships, invitations with a
one-time activation link, database-backed roles and permissions, administration pages and an
audit log. All existing project features work unchanged inside an organization. The
`miles/platform-client` package is still installed but switched off (`PLATFORM_APPLICATION=`
empty); its files in the shared `packages` folder were not touched.

Decisions applied: no data import (no production data), a tar snapshot instead of git,
path-based tenancy (`/o/{slug}`), Laravel Fortify, one-time activation links for invited users,
Mailtrap for mail.

## What changed

### Authentication (Fortify)

- Sign in, sign out, registration (switchable: `REGISTRATION_ENABLED`), email verification,
  forgot/reset password, password confirmation, profile and password change.
- Argon2id hashing (`config/hashing.php`); passwords of at least 12 characters with letters and
  numbers, checked against known breaches in production.
- Five sign-in attempts a minute per email and IP. Suspended users and accounts without a
  password (invited, not yet activated) cannot sign in.
- Forgot password gives the same answer whether or not the address has an account.
- A password reset signs out every session; a password change signs out the other sessions.
- Session cookie `projects_session`: HTTP-only, encrypted, SameSite=Lax, path `/`. CSRF on every
  form. Nothing is kept in localStorage.

### Organizations and tenancy

- `organizations`, `organization_memberships`, `invitations` tables. A foreign key from
  `organization_id` on all 13 tenant tables.
- `ResolveOrganization`: a non-member gets 404, a suspended organization or membership 403.
  Controllers never see the slug.
- `BelongsToOrganization` + `OrganizationScope` replace the platform's tenant trait. Reading
  tenant data without an organization throws (fails closed); writing into another organization
  throws.
- Creating an organization makes you its owner (`ORGANIZATION_CREATION`: `anyone` or
  `platform_admins`).
- Invitations: only a SHA-256 hash of the 48-character token is stored. They expire after
  `INVITATION_DAYS`, are single use, and can be resent (new token) or withdrawn. Someone new sets
  their name and password through the link; an existing account must sign in as the invited
  address.

### Roles and permissions

- `config/access.php` → `permissions`, `roles`, `role_permissions`, `role_assignments`, written
  by `php artisan access:sync` (also run by the seeder).
- System roles: Organization Administrator, Project Manager, Team Member, Viewer, Finance
  Controller. Owners hold every permission.
- Permissions are read from the database on every request, so a change takes effect on the next
  click. Every route keeps its server-side `can:` check.

### Administration (`organization.manage`, `organization.audit.view`)

- Members: invite, resend, withdraw, change roles, suspend, reactivate, remove (their work and
  history stay). Nobody can change their own membership or the owner's.
- Roles & permissions matrix, organization settings, and an audit log filterable by member.

### Audit

- `audit_events` (append-only model): organization events (`organization.*`, `member.*`,
  `invitation.*`) and account events (`auth.login`, `auth.login_failed`, `auth.lockout`,
  `auth.logout`, `auth.password_changed`, `auth.password_reset`, `auth.email_changed`, …), with
  IP and user agent.

### Removed or replaced

- Platform OIDC sign-in, `ResolveTenant`, `RequirePlatformAccess`, the per-request access API
  call, the platform outbox schedule, `App\Platform\SetUpWorkspace`.
- `@miles/ui` npm link → copied into `resources/js/ui/` (same import alias).

## Files

- **New**:
    - `app/Tenancy/*`, `app/Access/*`, `app/Audit/Audit.php`, `app/Actions/Organizations.php`.
    - `app/Actions/Fortify/*`, `app/Providers/FortifyServiceProvider.php`.
    - `app/Http/Controllers/{Root,Organization,Invitation,Account}Controller.php`,
      `app/Http/Controllers/Admin/*`.
    - `app/Http/Middleware/{ResolveOrganization,EnsureUserIsActive}.php`,
      `app/Http/Responses/PasswordResetLinkRequested.php`,
      `app/Notifications/InvitedToOrganization.php`.
    - Models: Organization, OrganizationMembership, Invitation, Role, RoleAssignment, AuditEvent.
    - `config/{fortify,access,security}.php`.
    - Pages: `auth/*` (6), `organizations/{index,create}`, `invitations/{show,invalid}`,
      `account/settings`, `admin/{members,roles,settings,audit}`. Layouts:
      `auth-layout`, `plain-layout`.
    - Tests: Authentication, Organizations, Invitations, Administration, Independence.
- **Changed**:
    - The User model, all 13 tenant models, 4 controllers, `routes/web.php`, `routes/console.php`.
    - `bootstrap/app.php`, `bootstrap/providers.php`, `config/hashing.php`.
    - `HandleInertiaRequests`, `SecurityHeaders`, `RememberPerson` (comment).
    - Frontend: `product-layout`, `navigation`, `app.tsx`, types, 36 route call sites (object
      form), `vite.config.ts`, `tsconfig.json`.
    - Config and docs: `.env`, `.env.example`, `phpunit.xml`, README.
- **Deleted**: `PlatformAccessTest`, `ProvisioningTest`, `app/Platform/SetUpWorkspace.php`, the
  stale `public/hot`.

## Migrations (all additive, run on local Postgres)

| Migration           | Adds                                                                                                                |
| ------------------- | ------------------------------------------------------------------------------------------------------------------- |
| `2026_11_03_000100` | users: password, email_verified_at, status, is_platform_admin, last_login_at, unique email; `password_reset_tokens` |
| `2026_11_03_000200` | organizations, organization_memberships, invitations                                                                |
| `2026_11_03_000300` | permissions, roles, role_permissions, role_assignments                                                              |
| `2026_11_03_000400` | audit_events                                                                                                        |
| `2026_11_03_000500` | FK organization_id → organizations on 13 tenant tables                                                              |

## Dependencies

- **Added**: `laravel/fortify` ^1.41 (pulls in `laravel/passkeys`, unused).
- **Updated**: `symfony/mime` 8.1.10 (security fix).
- **Removed**: the `@miles/ui` npm dependency.
- **Disabled, still installed**: `miles/platform-client`.

## Tests run

| Check                                                          | Result                                                  |
| -------------------------------------------------------------- | ------------------------------------------------------- |
| `php artisan test` (Pest)                                      | 49 passed, 580 assertions                               |
| `composer ci:check` (format, lint, types, Pint, PHPStan, Pest) | pass                                                    |
| `npx tsc --noEmit`                                             | no errors                                               |
| `npm run check`                                                | no lint warnings, formatting clean                      |
| `npm run build`                                                | builds                                                  |
| `php artisan migrate` on local Postgres                        | 5 migrations ran; `access:sync`: 9 permissions, 5 roles |

Manual smoke test on `php artisan serve` with Postgres:

- Guest pages return 200; protected pages redirect to `/login`.
- CSP, `X-Frame-Options: DENY` and an HTTP-only session cookie are sent.
- Sign-in stored an Argon2id hash and wrote `auth.login`; creating an organization wrote
  `organization.created`.
- Every admin page, `/account` and `/organizations` returned 200; another organization's address
  returned 404.

The smoke data was then cleared (`migrate:fresh --seed`; the database was empty before).

## Remaining issues

1. **Mailtrap credentials**: set `MAIL_USERNAME` and `MAIL_PASSWORD` in `.env`. Until then,
   verification, reset and invitation emails cannot be sent (registration will error on SMTP).
2. **No two-factor authentication** yet; Fortify supports it, so it can be turned on in a later
   phase.
3. **Project-level role grants**: `role_assignments.scope_type` exists, but only organization
   scope is honoured.
4. **No platform-admin UI**: suspending users and granting platform admin are console commands
   only (`users:status`, `users:platform-admin`).
5. **Registration reveals that an email is taken.** This is an accepted tradeoff; forgot-password
   does not reveal it.
6. **The `miles/platform-client` package is still installed**, though disabled. Removal is
   planned for Phase 4.
7. **PHPUnit 13.4.3 advisory** (dev only; Pest pins the version).
8. **`workspaces` and `organizations` both hold the name.** Settings keeps them in step; merge
   them later.
9. **Tests use SQLite**; Postgres was checked by migrating and the smoke test only.

## Next phase

Per the spec (section 17):

- Phase 3, data migration: nothing to migrate (no production data), recorded in
  `docs/phase-3-4-report.md`.
- Phase 4: remove the runtime dependencies (`miles/platform-client`, the platform env vars and
  tables). See `docs/phase-3-4-report.md`.
- The NGO, donor and beneficiary capabilities are Phase 6.
