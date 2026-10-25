# Integrating a product with the Miles Softwares platform

This is the contract every product (Retail, Pharmacy, School, …) implements. Laravel products get
all of it from [`packages/platform-client`](../packages/platform-client/README.md); a plain-PHP
reference lives in `examples/sample-app` (see the end of this page). Sending business events to
Finance, HR or Payroll, and health reporting (scopes `events.issue`, `health.write`), are in
`docs/shared-modules.md`.

All platform endpoints are on the **auth host**, `https://auth.milesoftwares.com`
(`http://auth.milesoftwares.localhost:8100` locally). Discovery:
`/.well-known/openid-configuration`.

## 0. Registration (done by platform staff)

In admin → Applications → _your product_ → Integration, staff register:

| Item                  | What you receive                     | Used for                        |
| --------------------- | ------------------------------------ | ------------------------------- |
| Sign-in client        | client id (+ secret if confidential) | user sign-in (section 1)        |
| Service client        | client id + secret                   | platform API (section 2)        |
| Webhook endpoint      | signing secret (`whsec_…`)           | webhooks and provisioning calls |
| Provisioning settings | your base URL and sync/async method  | tenant creation (section 4)     |

Secrets are shown once. Store them in your secret manager.

## 1. Sign-in (OpenID Connect)

Authorization code flow with **PKCE (S256), `state` and `nonce`, all mandatory**. Requests without
them are refused with `400 invalid_request`.

```
GET /oauth/authorize?client_id=…&redirect_uri=…&response_type=code&scope=openid profile email
    &state=<≥16 random chars>&nonce=<≥16 random chars>
    &code_challenge=<BASE64URL(SHA256(verifier))>&code_challenge_method=S256
```

- **Redirect URIs match exactly.** A product served on tenant hosts registers a _tenant callback
  path_ (e.g. `/retail/auth/callback`); the platform then allows exactly
  `https://{slug}.milesoftwares.com/retail/auth/callback` for every organization that is entitled
  to the product, and removes it when the entitlement ends or the slug changes.
- Exchange the code at `POST /oauth/token` (`grant_type=authorization_code`, `code`,
  `redirect_uri`, `code_verifier`, client credentials).
- **Verify the id_token**: RS256 signature against `/oauth/jwks` (select the key by `kid`),
  `iss` = the auth host URL, `aud` contains your client id, not expired, `nonce` equals yours.
- `sub` is the user's immutable id. Claims: `name`, `email`, `email_verified`.
- Access tokens live 10 minutes. Keep tokens server-side (BFF); never give refresh tokens to
  browser JavaScript.

## 2. Platform API (effective access)

Get a service token:

```
POST /oauth/token
grant_type=client_credentials&client_id=…&client_secret=…&scope=access.read
```

Then, with `Authorization: Bearer <token>`:

| Request                                                         | Answer                                    |
| --------------------------------------------------------------- | ----------------------------------------- |
| `GET /api/v1/organizations/{slug-or-id}`                        | id, slug, name, status, country, currency |
| `GET /api/v1/organizations/{id}/members/{sub}/effective-access` | see below                                 |
| `GET /api/v1/organizations/{id}/branches`                       | `data`: id, name, code, city, status      |
| `GET /api/v1/organizations/{id}/departments`                    | `data`: id, branch_id, parent_department_id, name, code, status |
| `GET /api/v1/organizations/{id}/positions`                      | `data`: id, department_id, title, code, status |

Branches, departments and positions are business structure, never personal data; the platform
offers no member directory to products (a product learns about a person when they sign in).
`PlatformApi::branches()`, `departments()` and `positions()` cache them like access answers.

Your token only ever sees **your own application**: organizations that never used it answer
`404`, and roles are those of your catalog only.

```json
{
    "allowed": true,
    "organization": { "id": "…", "slug": "manggala", "status": "active" },
    "application": { "code": "retail", "catalog_version": "1.0.0" },
    "subject": "…",
    "entitlement": {
        "active": true,
        "source": "subscription",
        "valid_until": null,
        "features": []
    },
    "membership": { "id": "…", "status": "active", "is_owner": false },
    "roles": [
        {
            "role": "cashier",
            "catalog_version": "1.0.0",
            "scope": { "scope_type": "branch", "branch_id": "…" },
            "permissions": ["retail.sales.create"],
            "expires_at": null
        }
    ],
    "max_age": 60
}
```

- Gate every request on `allowed` (entitled, active member, organization active, and a role or
  ownership). Then check each role's **scope** against the record being touched (branch,
  department); that check lives in your backend.
- Cache for at most `max_age` seconds, and drop the cache when a webhook arrives (section 3).
- `entitlement.features` lists the plan features the organization may use now, as
  `{ "key": "finance.fixed_assets", "limit": null }` (`limit` only for features that count
  something; `null` = no limit). A key that is missing means **off**: hide the pages and refuse
  the requests server-side, but never delete the records (section 5a).
- Errors: `401 invalid_token`, `403 insufficient_scope | client_credentials_required | unknown_client`,
  `404` (not visible to your application).

### A new business from your own app

An app of yours (desktop, mobile) can create its user's business. It sends the **person's**
access token from its **public** client, not a service token. These routes are under
`/api/v1/account` on the auth host:

| Method and path | What it does |
|---|---|
| `GET organizations/slug-availability?slug=` | `{ "available": bool, "errors": [...] }` |
| `POST organizations` | Creates the business, as "New business" on the dashboard does: `display_name`, `legal_name`, `slug`, `country_code`, `default_currency`, `timezone`, optional `business_type` and `contact_email`. It also needs `branch_name` and takes an optional `branch_city` for the first branch (code `MAIN`). |

- The person becomes the owner. The business gets the free trial of the application whose client
  sent the request, when that application offers trials.
- Answers `201 { "organization": { "id", "slug", "name" }, "trial": { "application", "ends_at" } | null }`.
  Next, open the workspace with the same sign-in.
- Errors: `401 invalid_token` (also for accounts that are no longer active),
  `403 user_token_required | unknown_client`, `403 email_unverified` (with a `message`: the person
  must confirm their address first), `422` validation errors.

## 3. Webhooks

`POST` to your endpoint, JSON body:

```json
{
    "event_id": "01a1…",
    "event_type": "access.changed",
    "schema_version": 1,
    "occurred_at": "2026-10-10T12:00:00+00:00",
    "organization_id": "…",
    "data": { "application": "retail", "membership_id": "…", "sub": "…" }
}
```

| Event                      | `data`                                | Sent to                    |
| -------------------------- | ------------------------------------- | -------------------------- |
| `entitlement.changed`      | `application`                         | that application           |
| `access.changed`           | `application`, `membership_id`, `sub` | that application           |
| `membership.revoked`       | `membership_id`, `sub`, `status`      | every entitled application |
| `membership.reactivated`   | `membership_id`, `sub`, `status`      | every entitled application |
| `organization.suspended`   | `status`                              | every entitled application |
| `organization.reactivated` | `status`                              | every entitled application |

Headers: `Miles-Event-Id`, `Miles-Event-Type`, and

```
Miles-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")>
```

Verify the HMAC over the **raw** body with a constant-time comparison and reject timestamps more
than 5 minutes off. Answer `2xx` quickly. Non-2xx answers are retried after 1 min, 5 min, 30 min,
2 h and 6 h, then the delivery is marked failed (staff can redeliver). **Deduplicate on
`event_id`**: the same event may arrive more than once.

## 4. Provisioning

When an organization becomes entitled, the platform calls

```
POST {your base URL}/tenants
Idempotency-Key: <step id>
Miles-Signature: …            (same scheme as webhooks, same secret)
```

```json
{
    "step_id": "…",
    "application": "retail",
    "organization": {
        "id": "…",
        "slug": "manggala",
        "display_name": "…",
        "country_code": "SS",
        "default_currency": "SSP",
        "timezone": "Africa/Juba"
    },
    "callback_url": "https://auth…/api/v1/provisioning/steps/{step}/complete"
}
```

- `2xx` (sync): the tenant exists. `202` (async): finish later by calling the `callback_url` with a
  service token that has `provisioning.write`: `{"status": "succeeded"}` or
  `{"status": "failed", "error": "…"}`.
- Other answers are retried on the webhook schedule, then marked failed; staff can retry.
- Treat `Idempotency-Key` as the tenant-creation key: the same key must never create two tenants.

## 5. Catalog publication

Roles and permissions are declared in a versioned JSON catalog (format:
`database/catalogs/platform.json`, `examples/sample-app/catalog.json`) and uploaded by staff in
admin → Applications. A published version never changes: publish a new version instead.
Assignments keep pointing at the version they were made under; `catalog_version` in the API shows
which.

## 5a. Plan features

A product declares its plan features in the same catalog, next to its permissions:

```json
"features": [
    { "key": "finance.fixed_assets", "name": "Fixed assets", "description": "…" },
    { "key": "retail.branches", "name": "Branches", "limit_unit": "branches", "status": "beta" }
]
```

Keys start with the application code. `status` is `available` (default), `beta` or `deprecated`
(cannot be added to more plans; existing plans keep it). `limit_unit` makes the feature take a
numeric limit; without it the feature is on or off. Adding features changes the catalog, so it
needs a new version.

Central then decides, per organization, which features are on. Staff can only choose features a
current catalog declares:

- **Plans** (admin → Plans) list the features of the applications they sell, with an optional
  limit. Editing a plan's features applies to every **active** subscription on it: a queued,
  retried job (`SyncPlanFeatures`), backed by a daily `platform:sync-plan-features`, revokes the
  rows of dropped features, writes rows for added ones and replaces changed limits. It is
  idempotent, so running it again changes nothing.
- **Overrides** (admin → Organization → Features) grant or deny one feature for one
  organization, with a required reason, an optional end date and the staff member recorded on
  the row and in the audit log. One override per feature is in effect; a new one replaces it,
  and "Back to the plan" removes it. Plan changes never touch overrides.

What a product receives is resolved from the current entitlement rows, first match wins:

1. an explicit denial in effect,
2. an explicit grant in effect,
3. a plan (subscription or trial) in effect (several plans: the largest limit; no limit wins),
4. otherwise off.

Expired or revoked rows never count, so a lapsed override falls back to the plan and a trial's
features end with the trial. Features are only sent while the organization has the application
itself.

Every change bumps the organization's access version and sends `entitlement.changed`, so
products see it on their next request; an override or trial reaching its end date shows within
`max_age` (60 s). Products must check features on every request (Finance: the `RequireFeature`
route middleware and the shared `multicurrency` / `fixedAssets` props), never only in the menu.
Withdrawing a feature hides and refuses it; the organization's records stay, and come back
unchanged if the feature is granted again. Tenant users have no API or page that changes
features; only staff with `plans.manage` (plans) and `entitlements.manage` (overrides) do.

## 6. Serving a product on workspace hosts

A product lives under its **route prefix** on every workspace host:
`https://{slug}.milesoftwares.com/pharmacy`. The launcher in the workspace links there, and the
sign-in client's tenant callback path is `/pharmacy/auth/callback`.

- The prefix is set in admin → Applications (lowercase, `^[a-z][a-z0-9-]*$`). Paths the workspace
  shell serves itself are refused (`platform.workspace_paths`: `members`, `manage`, `auth`, `build`, …).
- Product routes (and `miles/platform-client`) register under that path with
  `Route::prefix(...)`. The reverse proxy **preserves** the prefix; it does not strip it.
- Built assets go to `public/pharmacy/build` (Vite `buildDirectory` and `Vite::useBuildDirectory()`),
  so `/pharmacy/build/...` is a real file and never the platform's own `/build/`.
- The platform's calls (webhooks, provisioning) go to `…/pharmacy/platform/webhooks` and
  `…/pharmacy/platform` (base URL), on any host: they are signed and need no tenant.

**Production (nginx).** One file per product in `ops/nginx/products/`, copied to
`/etc/nginx/miles-products/`, which the HTTPS server block includes. Each product runs its own
php-fpm pool; REQUEST_URI keeps `/pharmacy/...` so it matches the prefixed Laravel routes.

**Local development (XAMPP Apache).** `ops/local-dev/apache-dev.conf` forwards
`{slug}.milesoftwares.localhost/pharmacy/*` to the product's `php artisan serve` with the path
preserved (`ProxyPass "/pharmacy/" "http://127.0.0.1:8400/pharmacy/"`). Ports:

| Product   | Folder                  | Dev port |
| --------- | ----------------------- | -------- |
| platform  | `central-app`           | 8100     |
| pharmacy  | `pharmacy-app`          | 8400     |
| blueprint | `templates/product-app` | 8499     |

Register a product folder with the local platform (catalog, prefix, clients, webhook, provisioning,
and the product's `.env`):

```bash
cd central-app
php artisan platform:local-product pharmacy-app --entitle=acme   # --fresh issues new secrets
```

### Several applications from one deployment

The ERP workspace application (ADR-0003) serves industry modules that are registered as separate
applications: `pharmacy-app` is registered as `pharmacy` and hosts `retail`. Each hosted
application has its own catalog, route prefix, sign-in and service clients and webhook secret, set
in `platform-client.hosted.<code>` (env `PLATFORM_<CODE>_*`). Its pages run behind
`['web', 'platform.app:<code>', 'platform']`; the package registers its sign-in, webhook and
provisioning routes under its path (route names `platform.<code>.login`, …). Organization lookups
and effective access are asked and cached per application, so access to one never opens the other,
and events it records are delivered with its clients and `source_app`. The proxy sends its path to
the same product (`ops/nginx/products/retail.conf`, `apache-dev.conf`).

```bash
php artisan platform:local-product pharmacy-app --catalog=platform/retail.json --prefix=retail --entitle=acme
```

## Sample app (local)

```
php artisan platform:sample-app --entitle=<workspace-slug>   # registers it, writes its config
composer dev                                                 # platform incl. 127.0.0.1:8101
php -S 127.0.0.1:8200 -t examples/sample-app/public          # the sample product
```

Open `http://127.0.0.1:8200/login?org=<workspace-slug>`. The home page shows the effective access,
and counts received webhooks and provisioned tenants. The queue worker in `composer dev` sends
webhooks and provisioning calls.
