# Miles Global Softwares — Platform Architecture

Status: **Proposed** (2026-10-09). Covers README §25 deliverables 1–9.
Decisions that later phases may revisit are recorded as ADRs in `docs/adr/`.

---

## 1. Proposed application architecture

```
                         ┌──────────────────────── Edge (TLS *.milessoftwares.com, apex) ───────────────────────┐
 Browser ───────────────►│ Reverse proxy / workspace gateway (Nginx or Caddy)                                     │
                         │  milessoftwares.com, www → apex          ─┐                                            │
                         │  auth. / app. / admin.                     ├─► CENTRAL PLATFORM (Laravel 13 monolith)  │
                         │  {slug}.  /  (workspace shell)            ─┘                                            │
                         │  {slug}.  /retail/*     ──────────────────────► Retail POS (independent deployment)    │
                         │  {slug}.  /finance/*    ──────────────────────► Finance (independent deployment)       │
                         │  {slug}.  /hr/*, /payroll/* … ────────────────► other products                         │
                         └────────────────────────────────────────────────────────────────────────────────────────┘
 Central platform ── PostgreSQL (platform DB) · Redis (cache, sessions, queues, rate limits) · Mail · Scheduler
 Products ── own databases; talk to the platform only via OIDC + versioned HTTP APIs + signed webhooks
```

**Central platform** is one Laravel 13 / PHP 8.4 application (modular monolith) that serves five
surfaces through host-based routing: marketing site, auth (IdP), customer dashboard, admin, and the
workspace shell. It is the system of record for identity, organizations, structure, memberships,
application registry, role assignments, subscriptions, entitlements, provisioning and audit.

**Business products** (Retail, Pharmacy, School, Finance, HR, Payroll, …) are separately developed and
deployed. They are OIDC relying parties of `auth.` and consume platform APIs. They own their operational
data and enforce their own operational permissions.

**Workspace gateway**: the edge proxy maps `{slug}.milessoftwares.com/{module}/…` to the product's
upstream, forwarding `X-Forwarded-Host`/`-Prefix`. The proxy does _not_ make authorization decisions; the
product validates the user's token and tenant context itself. Routing table is generated from the
application registry (`applications.gateway_upstream`, `applications.route_prefix`).

Frontend: React + TypeScript + Inertia.js, Tailwind CSS, shadcn/ui (Radix-based). Marketing pages use
Inertia SSR for SEO.

## 2. Module ownership matrix

| Module                                     | Owns (system of record)                                                                   | Exposes                                         | Consumes                                           |
| ------------------------------------------ | ----------------------------------------------------------------------------------------- | ----------------------------------------------- | -------------------------------------------------- |
| Identity                                   | users, credentials, MFA factors, sessions, authentication_events                          | OIDC/OAuth endpoints, `UserDirectory` interface | Audit, Notifications                               |
| Organizations                              | organizations, organization_slugs, org settings/status                                    | `TenantResolver`, `OrganizationQuery`           | Identity, Audit                                    |
| Structure (Departments/Branches/Positions) | departments, branches, positions, staff assignments                                       | scope lookups for Authorization                 | Organizations                                      |
| Memberships                                | organization_memberships, invitations                                                     | membership checks                               | Identity, Organizations                            |
| Applications                               | applications, application_clients, redirect URIs, role/permission catalogs (versioned)    | catalog API                                     | —                                                  |
| Authorization                              | application_access_grants, role_assignments, delegation rules, SoD constraint definitions | `AccessEvaluator`, effective-permissions API    | Memberships, Applications, Entitlements, Structure |
| Subscriptions                              | plans, plan_features, bundles, subscriptions, subscription_items                          | —                                               | Applications                                       |
| Entitlements                               | entitlements (derived from subscriptions + manual overrides)                              | `EntitlementChecker`, entitlement API           | Subscriptions                                      |
| Provisioning                               | provisioning_runs/steps, per-app tenant provisioning state                                | provisioning webhooks to products               | Organizations, Entitlements, Applications          |
| Notifications                              | notification templates/dispatch                                                           | `Notifier`                                      | —                                                  |
| Audit                                      | audit_logs (hash-chained), security events                                                | `AuditLogger`                                   | —                                                  |
| Administration                             | platform_staff, platform roles, support-access grants                                     | admin UI                                        | everything via public interfaces                   |
| Marketing                                  | product catalog content, demo_requests, contact_messages                                  | public pages                                    | Applications (catalog status)                      |

Outside the platform: **Finance** owns CoA, GL, journals, AR/AP, cash, statements, budget accounting.
**HR** owns employees, employment records, contracts, leave, attendance, extended position data.
**Payroll** owns salary structures, periods, runs, payslips, payroll approvals. **Procurement /
Inventory / Documents / Projects** — system of record defined per module in Phase 9 contracts.

Boundary rules (enforced by Pest architecture tests):

- A module touches another module's tables only through that module's `Contracts\*` interfaces or events.
- Controllers never query other modules' Eloquent models directly.
- Cross-module side effects go through domain events (e.g. `OrganizationCreated` → Provisioning).

## 3. Database relationship design (PostgreSQL)

Conventions: UUIDv7 primary keys (`HasUuids`, time-ordered) on all entities; `timestamptz`;
tenant-owned tables carry non-null `organization_id`; children reference tenant parents with
**composite foreign keys `(organization_id, id)`** so a row can never point into another tenant;
status columns use `CHECK` constraints; soft-deletes only where retention policy needs them.

```
users 1─* organization_memberships *─1 organizations 1─* organization_slugs (history; one is_primary)
users 1─* authentication_events            organizations 1─* departments (branch_id nullable → company-wide)
users 1─* mfa_factors                      organizations 1─* branches (manager_membership_id nullable)
                                           organizations 1─* positions
organization_memberships 1─* membership_branch_assignments  (multi-branch)
organization_memberships 1─* membership_department_assignments
organization_memberships *─1 positions (optional)

applications 1─* application_clients 1─* application_client_redirect_uris (exact URIs, per-tenant rows allowed)
applications 1─* application_catalog_versions 1─* application_roles / application_permissions
application_roles *─* application_permissions   (role_permission_mappings)
application_permissions: allowed_scope_types (normalized table application_permission_scope_types)
application_roles: allowed_scope_types, delegable flag, sensitivity level
sod_constraints: (application_id, permission_a_id, permission_b_id, mode)

application_access_grants: (organization_id, membership_id, application_id) unique; status
role_assignments: (organization_id, membership_id, application_id, application_role_id,
                   scope_type ∈ {organization, branch, department, resource},
                   branch_id?, department_id?, resource_ref?, granted_by_membership_id, expires_at?)
   CHECK: exactly the column matching scope_type is set; composite FKs to branches/departments
   UNIQUE (membership_id, application_role_id, scope_type, branch_id, department_id, resource_ref)

plans 1─* plan_features ; plans *─1 applications (or bundle via plan_applications)
subscriptions (organization_id, status, trial_ends_at, current_period_end, renews_at) 1─* subscription_items (plan_id, qty)
entitlements (organization_id, application_id, feature_key, limit_value, source, valid_from, valid_until)

invitations (organization_id, email, token_hash, proposed_access JSON validated at accept, expires_at, accepted_at)
provisioning_runs (organization_id, idempotency_key UNIQUE, status) 1─* provisioning_steps (application_id, status, attempts, last_error)
audit_logs (append-only; prev_hash, hash; actor, organization_id?, action, subject, ip, ua, context)
demo_requests, contact_messages
platform_staff (user_id, status) *─* platform_roles ; support_access_grants (staff, organization, reason, scope, expires_at)
```

Spec mapping notes:

- `role_assignment_scopes` is folded into `role_assignments`: **one assignment = one scope**. A user
  needing Branch A and Branch B gets two assignments. This makes "never merge scopes" structural and
  lets scope columns carry real FKs instead of polymorphic IDs.
- Permissions are rows, not JSON. The only JSON is `invitations.proposed_access`, which is re-validated
  against the inviter's delegation authority _at acceptance time_.
- Audit rows: no UPDATE/DELETE grants for the app DB role (Phase 10), plus a hash chain for
  tamper evidence.
- Retention: archived orgs → read-only for 90 days, export available, then hard-delete of tenant rows
  by a scheduled job; audit rows kept 7 years with PII minimized. Exact periods to confirm with
  Miles Global Link legal.

## 4. Identity and authorization design

**Identity (auth.milessoftwares.com).** Laravel Fortify for registration, verification, password reset,
TOTP MFA + recovery codes, behind our own Inertia UI. OAuth 2.0 / OIDC provider: **Laravel Passport

- a vetted OIDC layer** (ID tokens, discovery, JWKS, `nonce`) is the default proposal; Keycloak stays
  the alternative if the OIDC add-on fails evaluation in Phase 2 (ADR-0002). Passport alone is not
  treated as OIDC-compliant.

* Authorization Code + PKCE for all browser and mobile clients; client credentials for service-to-service.
* Redirect URIs matched exactly. Per-tenant callback URIs
  (`https://manggala.milessoftwares.com/retail/auth/callback`) are written into
  `application_client_redirect_uris` by provisioning. No wildcard matching.
* `state` and `nonce` are mandatory.
* Every host (auth, app, admin, each workspace, each product) has its own **host-only** session cookie
  (`__Host-` prefix where possible). No `Domain=.milessoftwares.com` cookies. SSO happens through the IdP
  session on `auth.` only.
* Tokens: short-lived access tokens (≤10 min) held server-side by each product's backend (BFF pattern).
  Browser JS never sees refresh tokens.
* Privileged accounts (platform staff, org owners) must use MFA. Account suspension revokes sessions and tokens.

**Authorization (hybrid).** The platform answers "is this user, in this org, entitled and assigned to
role R in application A at scope S?". The application answers "may this role perform this operation on
this record?".

Decision flow for a product request:

1. Verify token (issuer, audience = this app, expiry, signature).
2. Resolve tenant from the host → organization_id. It must equal the token's `org` claim.
3. Call `GET /api/v1/orgs/{org}/members/{sub}/effective-access?app=retail` (cached ≤60 s, bust via webhook).
   The response contains the entitlement status plus the list of role assignments with their scopes,
   and permissions expanded from the catalog version.
4. The application enforces permissions against record scope (branch/department) in its own backend.

Delegation: a member can grant role R at scope S only if (a) they hold `platform.access.manage` at
an organization scope or a scope containing S, (b) R is marked delegable and its sensitivity is ≤ the
grantor's highest sensitivity in that application, (c) the organization is entitled to the application,
and (d) the grantor is not the grantee for any sensitive role (no self-elevation). Org owners are bounded
only by (c).

Separation of duties: the catalog declares conflicting permission pairs (`create_payment` ⟂
`approve_payment`). The platform warns or blocks assignments that would give one user both
permissions at overlapping scopes. Transaction-level SoD ("creator ≠ approver") is enforced inside
each product.

Platform staff: a separate `platform_staff` record on the same identity, separate admin session on
`admin.`, mandatory MFA, platform roles (Super Admin … Security Auditor) kept apart from org roles. Access
to customer data requires a `support_access_grant` that is time-boxed, justified, scoped and audited.

## 5. Multi-tenant routing design

Host classes (config `platform.root_domain`; dev uses `milessoftwares.localhost`, which browsers
resolve to 127.0.0.1 without hosts-file edits):

| Host                                                                                       | Handler                                      |
| ------------------------------------------------------------------------------------------ | -------------------------------------------- |
| apex                                                                                       | Marketing (Inertia SSR)                      |
| `www.`                                                                                     | 301 → apex                                   |
| `auth.` / `app.` / `admin.`                                                                | Route groups `Route::domain(...)`            |
| reserved (`api, mail, support, status, cdn, static, docs, help, billing, dev, staging, …`) | 404 unless explicitly configured             |
| `{slug}.`                                                                                  | `ResolveTenant` middleware → workspace shell |
| anything deeper (`a.b.`)                                                                   | 404                                          |

`ResolveTenant`:

1. Takes the host only from the trusted proxy (`TrustHosts` + `TrustProxies` configured).
2. Checks the slug against `organization_slugs`. A primary slug resolves. A retired slug returns a 301
   to the primary during its grace period. An unknown slug returns 404 with no hint of other tenants.
3. Requires the organization to be `active`, or `suspended` (which shows a suspension page).
4. Binds `TenantContext(organization_id)` in the container. Tenant models get a global scope plus a
   fail-closed guard that throws if a tenant model is queried with no context.
5. Requires an active membership for the authenticated user. Org switching re-runs the check.

Slug rules: `^[a-z][a-z0-9-]{2,30}[a-z0-9]$`, no `--`, not reserved, not on the profanity or brand
blocklist, unique case-insensitively across current and retired slugs. Renames keep history, so the
immutable `organization_id` is unaffected.

Path-based product compatibility checklist (must pass before a product is placed under `/{module}`):

- **Assets/routing**: the app supports a base path (Vite `base`, router basename, `X-Forwarded-Prefix`).
- **Same-origin trust**: all modules on `{slug}.` share one origin. Cookie `Path` is _not_ a security
  boundary, and XSS in one module can act in another. Only first-party, reviewed products go under the
  gateway. Each module uses distinct HttpOnly cookie names and a strict CSP.
- **CSRF**: per-module token. SameSite=Lax cookies.
- **WebSockets**: the proxy forwards `Upgrade`. The path is prefixed (`/retail/ws`).
- **Callbacks/webhooks**: OIDC callbacks are tenant-path specific. Third-party webhooks go to a stable
  `api.` host per product, not tenant hosts.
- **PWA/offline**: service worker scope `/{module}/`, manifest `start_url` under the prefix, cache
  names namespaced.
  If a product fails the checklist, the fallback is a dedicated host `{module}.milessoftwares.com`
  using `org` from token and an org picker, documented per product (ADR).

TLS: `*.milessoftwares.com` + apex via ACME DNS-01. Wildcards cover one level only, so no
multi-level tenant hosts. DNS: wildcard A/AAAA record. Provisioning never touches DNS.

## 6. SaaS application integration strategy

Each product integrates through five contracts (Phase 8 delivers the SDK docs plus one sample app end to end):

1. **SSO**: an OIDC client registered in the application registry, with per-tenant redirect URIs and PKCE.
2. **Entitlement & access**: the effective-access API (above), plus webhooks `entitlement.changed`,
   `access.changed`, `membership.revoked`, `organization.suspended`. Webhooks are HMAC-signed,
   timestamped, retried with backoff, and carry an idempotency `event_id`.
3. **Catalog publication**: the product publishes its role and permission catalog (versioned JSON,
   validated and stored as rows) through an admin-approved upload or API. Catalog versions are
   immutable, and assignments reference the version they were made under.
4. **Provisioning**: the platform calls `POST {endpoint}/tenants` with `Idempotency-Key`. The product
   answers synchronously or with an async callback, and the platform tracks each step's state.
   Deprovisioning and suspension follow the same pattern.
5. **Business events (shared modules)**: products POST events directly to Finance, HR or Payroll APIs
   with client-credentials tokens issued by the platform. The org is bound in the token and the body
   org must match. Envelope: `{event_id, event_type, schema_version, organization_id,
source_app, source_transaction_id, occurred_at, payload}`. The receiver keeps a unique key on
   `(source_app, organization_id, source_transaction_id)`, so retries never double-post.
   Sensitive HR and Payroll fields are never in event payloads or general APIs.

## 7. Phase-by-phase implementation plan

| Phase              | Deliverables                                                                                                                                                                                                                                                                                                                                                                                                      | Acceptance (summary)                                                                                                                    |
| ------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| 1 Foundation       | Laravel 13 + Inertia/React/TS/Tailwind/shadcn; PostgreSQL + Redis config; module skeleton + arch tests; host routing for all host classes with placeholder pages; tenant resolver (slug lookup); core migrations (identity, orgs, slugs, structure, memberships, applications, catalog, authorization, commercial, operations, audit); Pest, Pint, Larastan, ESLint/Prettier, Vitest; GitHub Actions CI; git init | migrations run on PostgreSQL; unknown/reserved/multi-level hosts rejected by tests; CI-equivalent commands pass locally; build succeeds |
| 2 Central Auth     | Fortify flows, MFA, OIDC provider, sessions UI, login audit, security emails                                                                                                                                                                                                                                                                                                                                      | OIDC conformance smoke tests; PKCE/redirect/nonce negative tests                                                                        |
| 3 Orgs & tenancy   | create org, slug reservation/rename, membership, org switching, tenant guard                                                                                                                                                                                                                                                                                                                                      | cross-tenant tests by known ID; switching revalidates membership                                                                        |
| 4 Structure & RBAC | departments/branches/positions CRUD, catalog import, scoped assignments, delegation, SoD, access UI                                                                                                                                                                                                                                                                                                               | privilege-escalation and scope tests                                                                                                    |
| 5 Admin            | staff/roles, orgs, registry, plans, entitlements, security logs, support access                                                                                                                                                                                                                                                                                                                                   | MFA-gated; customer data not accessible without a grant                                                                                 |
| 6 Dashboard        | org dashboard, launcher, invitations, settings, subscription view                                                                                                                                                                                                                                                                                                                                                 | invitation expiry/single-use tests                                                                                                      |
| 7 Marketing        | catalog-driven pages, pricing, forms with spam protection, SEO/SSR                                                                                                                                                                                                                                                                                                                                                | Lighthouse/a11y checks; forms stored and notified                                                                                       |
| 8 Integration      | client registration, effective-access API, webhooks, provisioning API, sample app                                                                                                                                                                                                                                                                                                                                 | sample app SSO + entitlement end to end                                                                                                 |
| 9 Shared modules   | Finance/HR/Payroll contracts, idempotent processing, health reporting                                                                                                                                                                                                                                                                                                                                             | duplicate-event tests                                                                                                                   |
| 10 Production      | security review, load, backups, DNS/TLS, runbooks                                                                                                                                                                                                                                                                                                                                                                 | checklist signed off                                                                                                                    |

## 8. Project folder structure

The repository root holds `central-app/` (the Laravel application) next to `ops/` (deploy, backup,
web server and process configs), `docs/`, `packages/` (the shared-module kit), `contracts/` (event
schemas) and `examples/` (sample product and reference module). Paths below are inside `central-app/`.

The code is split by **site** (what a visitor sees, per host) on top of the **modules** (the shared
domain). A site owns its routes, controllers, middleware, pages, layouts and site-only components;
anything two sites need lives in a module (PHP) or in `resources/js/shared` (frontend).

| Site      | Hosts                                   | PHP                 | Routes                                                          | Frontend                     |
| --------- | --------------------------------------- | ------------------- | --------------------------------------------------------------- | ---------------------------- |
| `public`  | apex, `www.`                            | `app/Sites/Public`  | `routes/public.php`                                             | `resources/js/sites/public`  |
| `admin`   | `admin.`                                | `app/Sites/Admin`   | `routes/admin.php`                                              | `resources/js/sites/admin`   |
| `central` | `auth.`, `app.`, `{business}` workspace | `app/Sites/Central` | `routes/central.php` (+ `settings.php`, `oauth.php`, `api.php`) | `resources/js/sites/central` |

```
app/
  Modules/<Module>/      domain: Models, Actions, Support, Console, Providers (shared by all sites)
  Sites/<Site>/Http/     Controllers, Middleware, Requests of one site
  Http/                  shared middleware (Inertia, security headers, appearance) and base Controller
  Support/               cross-cutting: Hosts, Tenancy, Inertia (SitePages)
routes/ public.php admin.php central.php   loaded in that order: central ends with the {tenant} wildcard
resources/js/
  app.tsx                Inertia entry; pages and site layouts load on demand
  sites/index.ts         page name → file; layout per site
  sites/<site>/          pages/  layouts/  components/  index.ts (the site's layout rule)
  shared/                components (ui = shadcn), hooks, lib, types
  actions/ routes/ wayfinder/   generated by Wayfinder (not committed)
```

**Page names** are `{site}/{page}`: `Inertia::render('public/pricing')` renders
`resources/js/sites/public/pages/pricing.tsx`. The rule lives in `App\Support\Inertia\SitePages` and
`resources/js/sites/index.ts`; an unknown site or a missing page fails loudly in both.

**Adding a page:** create `sites/<site>/pages/<name>.tsx`, render `'<site>/<name>'` from a controller in
`app/Sites/<Site>`, and add the route to `routes/<site>.php`. **Adding a shared component:** put it in
`resources/js/shared/components` (shadcn's `components.json` already points there).

## 9. Technical risks and mitigations

| Risk                                                   | Mitigation                                                                                                                       |
| ------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------- |
| OIDC built on Passport is incomplete or non-conformant | Phase 2 spike against the OIDC conformance basic profile. If it fails → Keycloak (ADR-0002). Identity is kept behind interfaces. |
| Cross-tenant data leaks via a missing scope            | Fail-closed tenant guard, composite FKs, cross-tenant test suite per module, optional PostgreSQL RLS in Phase 10                 |
| Shared origin across `/module` paths                   | Only first-party vetted modules, strict CSP, distinct cookies, per-product fallback host (ADR)                                   |
| Per-tenant redirect URI growth                         | Rows generated by provisioning and indexed. Exact match only.                                                                    |
| Stale authorization cache in products                  | ≤60 s TTL + signed invalidation webhooks. Suspension also revokes tokens.                                                        |
| Partial provisioning appears active                    | Org status stays `pending` until all required steps succeed. Idempotency keys. Retry with backoff plus admin retry UI.           |
| Slug squatting/brand abuse                             | Reserved list + blocklist + admin override. Rename history.                                                                      |
| Windows dev vs Linux prod differences                  | CI on Ubuntu with PostgreSQL/Redis services is the source of truth                                                               |
| Audit tampering                                        | Append-only DB grants + hash chain + off-site log shipping (Phase 10)                                                            |
| Scope creep into product features                      | Products stay out of this repo. Only contracts and placeholders live here.                                                       |
