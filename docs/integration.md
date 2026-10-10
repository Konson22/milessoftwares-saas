# Integrating a product with the Miles Softwares platform

This is the contract every product (Retail, Pharmacy, School, …) implements. A working
reference lives in `examples/sample-app` (see the end of this page).

All platform endpoints are on the **auth host**, `https://auth.milessoftwares.com`
(`http://auth.milessoftwares.local:8100` locally). Discovery:
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
  `https://{slug}.milessoftwares.com/retail/auth/callback` for every organization that is entitled
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
- Errors: `401 invalid_token`, `403 insufficient_scope | client_credentials_required | unknown_client`,
  `404` (not visible to your application).

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

## Sample app (local)

```
php artisan platform:sample-app --entitle=<workspace-slug>   # registers it, writes its config
composer dev                                                 # platform incl. 127.0.0.1:8101
php -S 127.0.0.1:8200 -t examples/sample-app/public          # the sample product
```

Open `http://127.0.0.1:8200/login?org=<workspace-slug>`. The home page shows the effective access,
and counts received webhooks and provisioned tenants. The queue worker in `composer dev` sends
webhooks and provisioning calls.
