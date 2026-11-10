# Phase 1 — Repository and Architecture Audit

Date: 2026-11-03 · Scope: `D:\new-projects\project-management` · Status: **read-only audit, no code or data changed**

Method: read every backend, frontend, config, migration and test file; read the `miles/platform-client`
package the app depends on; read the central platform's identity schema; ran the test suite and took
read-only row counts of the live database.

---

## 0. Headline findings

1. **The app already has its own dedicated PostgreSQL database** (`projects` on 127.0.0.1:5432), its
   own migrations, and no cross-database queries. The "central database dependency" is not a shared
   database. It is a **runtime HTTP API dependency** on the central platform, used for sign-in, tenant
   lookup and per-request access checks.
2. **All coupling goes through one package, `miles/platform-client`**, plus the frontend UI kit `@miles/ui`.
   Both are path dependencies on `../packages/*`, which is a symlink to `D:\new-projects\milesoftwares\packages`.
   Those packages are shared with other Miles apps and **must not be edited**. We replace them inside
   this app instead.
3. **The local database is empty.** All 20 tables have 0 rows. Whether a production deployment holds
   real data is unknown, and that decides how much of Phase 3 is needed (see §7).
4. **This folder is a copy of `milesoftwares/projects-app`.** The source is byte-identical. Changing it
   does not affect the original, but **it is not a git repository**, so there is no code rollback path yet.
5. Baseline: **19/19 Pest tests pass** (219 assertions). PHP 8.4.26 has Argon2id available.
6. The existing domain (projects, tasks, board/list/timeline, dependencies, time, budget lines,
   expenses, risks) is well-built and tenant-safe. A migration path that keeps all of it is clearly
   feasible, so **no rebuild is warranted**.

---

## 1. Current application architecture

| Layer                    | Technology                                                                                                   |
| ------------------------ | ------------------------------------------------------------------------------------------------------------ |
| Backend                  | Laravel 13.17, PHP 8.4, controllers + small action classes (`app/Actions`), support services (`app/Support`) |
| Frontend                 | Inertia 3 + React 19 + TypeScript, Tailwind 4, Vite 8 (`vite-plus`), Laravel Wayfinder for typed routes      |
| UI kit                   | `@miles/ui` (shared shell: AppShell, sidebar, user menu, quick search, cards, tables): `file:../packages/ui` |
| Database                 | PostgreSQL (`DB_CONNECTION=pgsql`, db `projects`); tests use in-memory SQLite                                |
| Sessions / cache / queue | `database` drivers; session cookie `miles_projects_session`, path `/projects`, encrypted                     |
| Tests                    | Pest 5 (feature tests against a faked platform), PHPStan/Larastan, Pint                                      |
| Hosting model            | Served at `{workspace-slug}.milesoftwares.localhost/projects`; the tenant comes from the **subdomain**       |

**Request pipeline (today):**

```
browser → web → SecurityHeaders (pkg) → platform.tenant (pkg: subdomain → PlatformApi::organization)
        → platform.access (pkg: session tenant check → PlatformApi::effectiveAccess, cached 60 s)
        → RememberPerson (app: mirror user into `people`) → route `can:projects.*` → controller
```

Authorization: the package registers a `Gate::before` hook. Any dotted ability (`projects.view`) is
true if the platform's EffectiveAccess for the member lists it. Roles and permissions are defined in
`platform/catalog.json` and assigned **on the central platform**, not here.

Tenancy: models use the `BelongsToTenant` trait (package). A global scope filters on `organization_id`
and **fails closed** without a tenant. Writes into another tenant throw. Nested route bindings use
`scopeBindings()`, and child records are re-checked against their project (`abort_unless(... === $project->id, 404)`).

### Routes (`routes/web.php`): 40 product routes, all behind `['web','platform', RememberPerson]`

Home/portfolio · My work · People (CRUD, cost rates) · Projects (index/create/show/edit/status) ·
Board / Tasks list / Timeline / Task detail (create, update, move, comment, dependencies) · Team
(members, milestones) · Time (log/delete) · Budget (lines, expenses) · Risks.
Package routes: `auth/login`, `auth/callback`, `logout`, `api/session`, `platform/webhooks`, `platform/tenants`.

### Frontend

15 pages (`resources/js/pages`), 7 components, one layout (`product-layout.tsx`, built on the
`@miles/ui` shell). Navigation is static (`lib/navigation.ts`) and filtered by permission. The UI hides
actions only; every action is re-authorized on the server. **No tokens are kept in localStorage**:
auth is a session cookie only.

---

## 2. SSO implementation analysis: dependency inventory

| #   | Dependency                             | Where                                                                                                         | What it does                                                                                                   | Replacement                                                                                          |
| --- | -------------------------------------- | ------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------- |
| S1  | OIDC authorization-code + PKCE sign-in | pkg `SignIn`, `SignInController` (`/auth/login`, `/auth/callback`)                                            | Redirects to `PLATFORM_ISSUER/oauth/authorize`, redeems the code, verifies the RS256 id_token against the JWKS | Local login (Fortify)                                                                                |
| S2  | JWT/JWKS verification                  | pkg `KeySet`, `AppTokens`, `lcobucci/jwt`                                                                     | id_token and mobile access-token checks                                                                        | Not needed (session auth)                                                                            |
| S3  | Guest redirect                         | `bootstrap/app.php` → `route('platform.login')`                                                               | Sends guests to the platform                                                                                   | `route('login')`                                                                                     |
| S4  | Logout                                 | pkg `SignInController::logout`; `product-layout.tsx` imports `@/routes/platform` `logout`                     | Ends the product session, back to the workspace home                                                           | Local logout route                                                                                   |
| S5  | User mirror                            | `users` table: uuid PK = **platform subject**, name, email, no password; upserted at each sign-in             | Identity record                                                                                                | Same table + password, verification, status columns. **Keep the UUIDs**                              |
| S6  | Per-request access gate                | pkg `RequirePlatformAccess` → `PlatformApi::effectiveAccess` (HTTP, cached 60 s); 503 if the platform is down | Membership, entitlement, roles                                                                                 | Local membership + role assignment check                                                             |
| S7  | Permission resolution                  | pkg `Gate::before` + `CurrentAccess`; `HandleInertiaRequests` shares `access.permissions` / `isOwner`         | `can('projects.*')`                                                                                            | App `Gate::before` reading local role assignments. **Same permission keys, so routes are unchanged** |
| S8  | Session-to-tenant binding              | `RequirePlatformAccess::SESSION_TENANT`                                                                       | A session only works on the workspace it signed in to                                                          | Membership check against the URL's organization                                                      |
| S9  | Mobile bearer API                      | pkg `RequirePlatformToken`, `AppSessionController`                                                            | **Unused by this app** (no API routes)                                                                         | Drop; add Sanctum later if a mobile app is needed                                                    |
| S10 | Webhooks (access/org changes)          | pkg `WebhookController` → cache bust                                                                          | Revocation propagation                                                                                         | Not needed: access is local and immediate                                                            |
| S11 | Test harness                           | `tests/TestCase` uses `InteractsWithPlatform` (`actingAsMember`, `platform()->organization`, `asTenant`)      | All 19 tests                                                                                                   | Local test helpers with the same shape, so tests port mechanically                                   |

## 3. Central-platform dependency analysis

There is **no shared database connection** and **no cross-database query** anywhere (checked: `config/database.php`,
every `DB::` call, every model). Coupling is entirely through the platform API and shared packages:

| #   | Dependency                        | Where                                                                                                                 | Replacement                                                                                                 |
| --- | --------------------------------- | --------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| C1  | Organization lookup by subdomain  | pkg `ResolveTenant` → `PlatformApi::organization($slug)`. Supplies id, slug, display name, currency, timezone, status | Local `organizations` table                                                                                 |
| C2  | Organization provisioning         | pkg `ProvisioningController` → `App\Platform\SetUpWorkspace` creates the `workspaces` row                             | Local "create organization" action                                                                          |
| C3  | Memberships / roles / owners      | Central `organization_memberships`, `role_assignments`, `application_access_grants` (read through the API)            | Local `organization_memberships`, `roles`, `role_assignments`                                               |
| C4  | Tenant primitives                 | pkg `BelongsToTenant`, `TenantScope`, `CurrentTenant`, used by **13 models** and 4 controllers                        | Re-implement in `app/Tenancy`. Same column (`organization_id`), same fail-closed semantics                  |
| C5  | Outbox to shared Finance module   | pkg `Outbox`, `platform:outbox` scheduled every minute (`routes/console.php`), `platform_outbox` table                | Unused: the app never records events and `PLATFORM_FINANCE_EVENTS_URL` is empty. Unschedule; keep the table |
| C6  | Security headers / CSP            | pkg `SecurityHeaders` (`bootstrap/app.php`)                                                                           | Copy the middleware into the app                                                                            |
| C7  | Asset path & cookie path coupling | `PlatformConfig::path()` drives the route prefix, Vite build dir, CORS regex for `*.milesoftwares.*`                  | App config (`APP_PATH`, default `/`)                                                                        |
| C8  | Frontend UI kit                   | `@miles/ui` (`file:../packages/ui`), imported by 23 files                                                             | Vendor the ~1,750 lines into `resources/js/ui` (no new npm deps)                                            |
| C9  | Composer path repos               | `composer.json` → `../packages/platform-client`, `../packages/shared-module-kit`                                      | Remove after cutover; `lcobucci/jwt` leaves with them                                                       |

Unrelated central apps (finance, hr, pharmacy, ...) are untouched by anything proposed here.

---

## 4. Existing feature inventory vs. the target

✅ exists and works · 🟡 partial · ❌ absent

| Area                                                                                                    | Status | Notes                                                                                              |
| ------------------------------------------------------------------------------------------------------- | ------ | -------------------------------------------------------------------------------------------------- |
| Projects (code, manager, dates, status lifecycle, budget)                                               | ✅     | No programmes, templates, lifecycle stages                                                         |
| Tasks: board / list / timeline (Gantt)                                                                  | ✅     | Single assignee; fixed statuses; no subtasks, checklists, recurrence, custom fields                |
| Task dependencies (cycle-safe, blocks completion)                                                       | ✅     |                                                                                                    |
| Comments, activity log per project                                                                      | ✅     | No mentions, attachments, notifications                                                            |
| Milestones, team & allocation                                                                           | ✅     |                                                                                                    |
| Time tracking & weekly timesheet, costed at the person's rate                                           | ✅     |                                                                                                    |
| Budget lines by category vs actuals; expenses                                                           | 🟡     | No approval workflow, versioning, commitments, funding sources or multi-currency. **Hard deletes** |
| Risk register                                                                                           | ✅     |                                                                                                    |
| Portfolio health (`ProjectMetrics`)                                                                     | ✅     |                                                                                                    |
| People directory (staff + contractors, cost rates)                                                      | ✅     |                                                                                                    |
| Local auth, users, orgs, RBAC, admin UI, audit log                                                      | ❌     | All on the central platform today                                                                  |
| Project-level access / donor visibility                                                                 | ❌     | Every grant is organization-wide: a viewer sees **all** projects                                   |
| Grants/donors, procurement, beneficiaries, MEAL, data collection, AI, App Center                        | ❌     |                                                                                                    |
| Workspaces → Spaces → Folders → Lists, Docs, Goals/OKRs, sprints, automation, dashboards, global search | ❌     | Quick search covers navigation only                                                                |
| Deployment (Docker, backups, health)                                                                    | 🟡     | `/up` health route only                                                                            |

---

## 5. Proposed standalone architecture

Keep the stack (Laravel 13 / Inertia / React / PostgreSQL) and the existing database. Replace the
package with in-app modules:

```
app/
  Identity/      local auth (Laravel Fortify), users, invitations, legacy identity map
  Tenancy/       Organization, CurrentOrganization, BelongsToOrganization trait + scope (fail closed)
  Access/        roles, permissions, scoped role assignments, Gate::before resolver, policies
  Audit/         audit events (auth, permission changes, financial actions)
  Projects/ ...  existing domain, then Finance, Grants, Procurement, Beneficiaries, MEAL as separate domains
```

**Authentication.** Use **Laravel Fortify**: Laravel's official headless backend for registration, login,
logout, email verification, password reset/confirm, optional TOTP 2FA, and login rate limiting.
Inertia React pages sit on top. Hashing driver set to `argon2id`. Database sessions, HTTP-only, `Secure`
in production, `SameSite=Lax`, session regenerated on login, Laravel CSRF on every web form. No custom
token protocol and nothing in localStorage.

**Tenant resolution.** Move from `{slug}.domain/projects` to path-based **`/o/{organization-slug}/...`**.
This needs no wildcard DNS or TLS, works for users in several organizations (an org switcher), and lets
bookmarks carry the organization. A middleware validates active membership, sets `CurrentOrganization`,
and then the same fail-closed scope applies. Records keep their existing `organization_id` values.

**Authorization.** Thin local RBAC, seeded from `platform/catalog.json` so **every permission key
(`projects.view`, …) and route middleware stays unchanged**:

- `permissions` (key, name, sensitivity); `roles` (per organization, or system templates);
  `role_permissions`.
- `role_assignments` (membership, role, `scope_type` = organization | programme | project | funding_agreement,
  `scope_id`, expires_at, granted_by). This adds project-level grants for donors and partners, which the
  current model cannot express.
- `Gate::before` resolves the scope from the route's bound project. Policies cover record-level rules
  (own time entries, donor-visible fields).
- Platform administrator is a flag on `users`, kept separate from organization roles. Beneficiary
  portal identities are a **separate guard and table** (later phase).

A small custom model is chosen over `spatie/laravel-permission`. Spatie supports one "team" dimension;
we need organization- and project-scoped grants, and the existing catalog semantics map 1:1.

**Transition switch.** The package only registers its routes when `PLATFORM_APPLICATION` is set. Adding
`AUTH_MODE=platform|local` lets both paths coexist until cutover, and rollback is an env change.

## 6. Proposed database schema changes (all additive)

| Table                                                                                 | Change                                                                                                                                                                                                                                                                       |
| ------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `users`                                                                               | add `password` (nullable until activated), `email_verified_at`, `status` (active/suspended/pending_activation), `is_platform_admin`, `last_login_at`, 2FA columns; unique index on `lower(email)`. **Existing UUIDs kept**, so every `created_by` / `user_id` FK stays valid |
| `organizations`                                                                       | new: `id` (= existing `organization_id` values), `slug` unique, `legal_name`, `display_name`, `country_code`, `default_currency`, `timezone`, `status`, `capabilities` (jsonb: donor, implementing_partner, …; **not** an exclusive type)                                    |
| `workspaces`                                                                          | keep (per-org product settings), add FK to `organizations`. **Naming clash:** ClickUp "Workspace" means something else, so the ClickUp hierarchy should be named Spaces/Folders/Lists to avoid overloading it                                                                |
| `organization_memberships`                                                            | new: org, user, status, is_owner, invited_by, joined_at, suspended_at                                                                                                                                                                                                        |
| `invitations`                                                                         | new: org, email, role, token hash, expires_at, accepted_at                                                                                                                                                                                                                   |
| `permissions`, `roles`, `role_permissions`, `role_assignments`                        | new (above)                                                                                                                                                                                                                                                                  |
| `password_reset_tokens`                                                               | new (Laravel standard, hashed tokens, 60-minute expiry)                                                                                                                                                                                                                      |
| `legacy_identity_map`                                                                 | new, restricted: `user_id`, `platform_subject`, `platform_email`, `email_verified_on_platform`, `activation_state`, `verified_at`. No UI exposure; dropped only after migration sign-off                                                                                     |
| `audit_events`                                                                        | new: org, actor, action, subject type/id, before/after (jsonb), ip, user agent, at                                                                                                                                                                                           |
| `people` FK to `organizations`; all tenant tables get an `organization_id` FK + index | new constraints                                                                                                                                                                                                                                                              |

Financial-integrity changes (Phase 6, flagged now): budget lines, expenses and time entries are
**hard-deleted today** (`BudgetController@removeLine/removeExpense`, `TimeController@destroy`). They should
become void/reversal records with an audit trail before any donor reporting relies on them.

## 7. Data migration and rollback strategy

**Product data needs no move.** It is already in the app's own database, keyed by `organization_id` and
user UUIDs that we keep. Only the identity and access data now held centrally must be imported:

| From central (read-only export, never modified)                                  | To local                                                        |
| -------------------------------------------------------------------------------- | --------------------------------------------------------------- |
| `organizations` (+ primary `organization_slugs`) for orgs entitled to `projects` | `organizations` (same ids)                                      |
| `users` that exist in the local `users` table                                    | merge into `users` (same ids), plus a `legacy_identity_map` row |
| `organization_memberships` (+ `is_owner` from `owner_membership_id`)             | `organization_memberships`                                      |
| `role_assignments` for application `projects` (role key → local role)            | `role_assignments` (organization scope)                         |

**Passwords:** not migrated. Every migrated user gets `status=pending_activation` and an **activation
link** (single-use, hashed, expiring) sent to their email. Setting a password verifies the email.
Owners and managers must activate this way; **no automatic matching of privileged accounts**.
Central passkeys, 2FA secrets and social logins do not carry over; users re-enrol. (The central app
uses Laravel bcrypt, so importing hashes is technically possible. That is your call, and I don't recommend it.)

**Procedure:** (1) `pg_dump` both databases → (2) restore copies → (3) run `php artisan identity:import --dry-run`
against the copy → (4) reconciliation report: counts per table per org before/after, orphan-FK check
(every `created_by`/`user_id`/`person_id` resolves), every member has ≥1 role, permission-equivalence
check (old EffectiveAccess vs new resolver for each user) → (5) conflicts (duplicate emails, users without
membership) go to a manual-review table, never fabricated → (6) sign-off → production run.

**Rollback:** schema changes are additive, so `AUTH_MODE=platform` restores the old path instantly. The
pre-cutover `pg_dump` is the data rollback. Package removal and `PLATFORM_*` config cleanup happen only
after production verification (Phase 4).

## 8. Security and authorization strategy

- Server-side enforcement only: route `can:` middleware (existing) + policies + fail-closed tenant scope.
- Cross-tenant tests for every resource type (one exists today: "keeps each workspace's projects to itself").
- Rate limits: login 5/min per email+IP, password reset and verification throttled, invitations throttled.
- Audit: logins, failures, password changes, role/membership changes, financial approvals.
- Keep the existing CSP / X-Frame-Options / HSTS policy (copy the middleware).
- Secrets only in `.env` / a secret manager; `.env.example` without values; DB credentials never reach Inertia props.
- Donor and beneficiary data: field-level visibility through policies and dedicated donor-facing
  resources/DTOs, never by hiding fields in React.
- External credentials (Kobo, OpenAI, M365) encrypted with Laravel's encrypter, per connection (Phase 8).

Current weaknesses to fix along the way: no code VCS (high); hard deletes of financial records;
organization-wide grants only; every request 503s when the platform is down (that is the independence problem itself).

## 9. Implementation phases (refined)

**Phase 2 — standalone foundation** (next, behind `AUTH_MODE`):
2a `git init` + baseline commit · 2b vendor `@miles/ui` into the repo · 2c `app/Tenancy` (swap the trait on
13 models; behaviour identical) · 2d organizations, memberships, invitations · 2e Fortify auth +
Argon2id + React auth pages · 2f local RBAC seeded from the catalog + `Gate::before` · 2g path-based org
routing + org switcher · 2h port the 19 tests to local helpers, plus auth / isolation / RBAC tests · 2i admin
screens (users, members, roles, audit log).

**Phase 3** identity import command + reconciliation (only if a production deployment has data).
**Phase 4** remove the package, `PLATFORM_*` config, outbox schedule, composer path repos; Docker,
`.env.example`, backup/restore docs.
**Phase 5** domain restructure (`app/Projects`, …).
**Phase 6** finance integrity (versioned budgets, approvals, commitments, funding sources, FX), then grants, procurement, MEAL, beneficiaries, donor portal.
**Phase 7** ClickUp-style hierarchy and views. **Phase 8** App Center, Kobo/ODK, OpenAI.

## 10. Risks and dependencies

| Risk                                         | Impact                               | Mitigation                                                                             |
| -------------------------------------------- | ------------------------------------ | -------------------------------------------------------------------------------------- |
| Not a git repo                               | No code rollback                     | `git init` before any change                                                           |
| Unknown production data                      | Phase 3 size                         | Confirm whether a deployed instance exists                                             |
| URL scheme change (subdomain → `/o/{slug}`)  | Old bookmarks break                  | Redirect route for old paths                                                           |
| Shared packages are symlinked                | Editing them breaks other Miles apps | Never edit `vendor/miles/*`; copy what we need                                         |
| Users lose access at cutover                 | Support load                         | Activation emails sent before cutover; requires working SMTP (`MAIL_MAILER=log` today) |
| "Workspace" naming clash                     | Confusing schema and UI              | Settle naming in Phase 2                                                               |
| Scope (sections 8–14) is many months of work | Half-built modules                   | Phase gates; no placeholder pages; nav shows only implemented features                 |
| Mail provider needed for verification/reset  | Auth flows unusable without it       | Configure SMTP/Postmark/SES in `.env`                                                  |
