# Admin → Applications → Integration

`https://admin.milesoftwares.com/applications/<application id>/integration`

This page connects one **product** (Finance, Pharmacy, Retail & POS, …) to the platform. Until
it is filled in, the product cannot sign people in, cannot ask the platform who may do what,
and never hears about changes, so its pages return errors.

Example: `/applications/01a15620-2a5c-709b-9916-25836ac046fb/integration` is **Finance**. On
21 Oct 2026 it had no clients, no webhook and provisioning set to "None".

Who can use it: staff with the `registry.manage` permission (role `catalog_admin` or
`super_admin`). The technical contract behind every field is in `docs/integration.md`.

---

## What the page shows

### Header

- **"<Product> integration"**, with a link back to Applications.
- **The one-time secret box (yellow).** It appears right after you register a client, rotate a
  secret or create a webhook. It shows the **Client ID** and the **Secret** once. Copy both
  straight into the product's `.env` (see below). After you leave the page the secret cannot be
  shown again; the only fix is to rotate it, which issues a new one.

### Clients (left column)

The product's credentials on the platform. Each client shows its name, its type, its Client ID
and, for sign-in clients, its callback addresses.

| Type             | What it is for                                                                                       |
| ---------------- | ---------------------------------------------------------------------------------------------------- |
| **confidential** | A **sign-in client** for a server-side web app (all our products). Has a Client ID and a secret.       |
| **public**       | A sign-in client for a browser-only or mobile app. No secret (PKCE). Not used by our products.        |
| **service**      | The product's **backend calling the platform API** (who may do what, branches, features). ID + secret. |

Every product needs **one confidential sign-in client and one service client**.

Buttons on each active client:

- **Rotate secret**: issues a new secret and shows it once. The old secret stops working
  immediately, so update the product's `.env` straight away.
- **Disable**: the client stops working at once (sign-in or API calls fail). Use it when a
  secret has leaked, then register a new client.

**New sign-in client** form:

| Field                        | What to enter                                                                                                                                                                                 |
| ---------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Name                         | Free text, e.g. `Finance web`.                                                                                                                                                                 |
| Type                         | **Confidential (server-side web app)** for our products.                                                                                                                                       |
| Per-tenant callback path     | Where each workspace's sign-in returns: `/<workspace path>/auth/callback`, e.g. `/finance/auth/callback`. The platform registers `https://<slug>.milesoftwares.com/finance/auth/callback` for every organization that may use the product, and shows the count. |
| Fixed callback URLs          | Leave empty for our products. Only for an app on one fixed address.                                                                                                                           |

**New service client (API)**: just a name, e.g. `Finance backend`, then Register.

### Webhooks (right column, top)

Where the platform sends **events** to the product: entitlement changes, role changes, members
suspended, organizations suspended. The product uses them to drop cached access immediately.

- **Endpoint URL**: `https://milesoftwares.com/<workspace path>/platform/webhooks`, e.g.
  `https://milesoftwares.com/finance/platform/webhooks`.
- **Create endpoint** (first time) issues the **signing secret** (`whsec_…`), shown once in the
  yellow box. The product uses it to verify that events really come from the platform.
- **Issue a new signing secret**: tick it and Save to rotate. Update the product `.env` at once.

Below the form, the **last 30 deliveries**:

| Status      | Meaning                                                                         |
| ----------- | ------------------------------------------------------------------------------- |
| `delivered` | The product answered 2xx.                                                       |
| `pending`   | Waiting to be sent or retried. Retries back off for up to 6 hours.              |
| `failed`    | Gave up after the retries. The last HTTP code and error are shown.              |

**Redeliver** sends a failed or pending event again. Typical causes of failures: the product's
`.env` has a different webhook secret (HTTP 401/403), or the product is down (HTTP 5xx or a
timeout).

### Provisioning (right column, bottom)

How the product is told that a **new organization** may use it, so it can set that
organization up (database rows, defaults) before the first person arrives.

| Method                          | Meaning                                                                                                                 |
| ------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| None                            | The platform never calls the product.                                                                                   |
| Manual                          | Staff set the organization up by hand.                                                                                  |
| **API, synchronous**            | The platform calls `POST <base URL>/tenants` and waits for the answer. **Use this for our products.**                    |
| API, asynchronous (callback)    | The product answers "accepted" and reports back later.                                                                  |

- **Base URL**: `https://milesoftwares.com/<workspace path>/platform`, e.g.
  `https://milesoftwares.com/finance/platform`. Calls are signed with the webhook secret, so
  create the webhook first.

Below the form, the **last 30 provisioning attempts**, one per organization: `succeeded`,
`pending` or `failed` with the error. **Retry** on a failed one calls the product again (for
example after fixing its `.env`).

---

## Setting up a product, step by step

Do these in order. Example values are for Finance; the table further down has every product.

1. **Workspace path** (admin → **Applications**, the product's row): set it to `finance`. The
   product then lives at `https://<slug>.milesoftwares.com/finance`. It must be lowercase,
   unique, and not a path the workspace already uses.
2. Open **Integration** for the product.
3. **New sign-in client**: Name `Finance web`, Type *Confidential*, Per-tenant callback path
   `/finance/auth/callback`, then Register. Copy the Client ID and Secret from the yellow box.
4. **New service client**: Name `Finance backend`, then Register. Copy the Client ID and Secret.
5. **Webhooks**: Endpoint URL `https://milesoftwares.com/finance/platform/webhooks`, then
   Create endpoint. Copy the signing secret.
6. **Provisioning**: Method *API, synchronous*, Base URL
   `https://milesoftwares.com/finance/platform`, then Save.
7. Put the five values into the product's `.env` on the server (next section) and reload it.
8. Back on this page, **Retry** any failed provisioning steps for organizations that already
   had the product.
9. Check: open `https://<slug>.milesoftwares.com/finance` for an organization that has
   Finance. You should be sent to sign in and land on the product.

### Values per product

| Product       | Workspace path | Per-tenant callback path  | Webhook endpoint URL                                    | Provisioning base URL                        |
| ------------- | -------------- | ------------------------- | ------------------------------------------------------- | -------------------------------------------- |
| Finance       | `finance`      | `/finance/auth/callback`  | `https://milesoftwares.com/finance/platform/webhooks`   | `https://milesoftwares.com/finance/platform` |
| Pharmacy      | `pharmacy`     | `/pharmacy/auth/callback` | `https://milesoftwares.com/pharmacy/platform/webhooks`  | `https://milesoftwares.com/pharmacy/platform`|
| Retail & POS  | `retail`       | `/retail/auth/callback`   | `https://milesoftwares.com/retail/platform/webhooks`    | `https://milesoftwares.com/retail/platform`  |

Retail & POS has no server folder of its own: pharmacy-app serves it at `/retail`, so its
values go into **Pharmacy's** `.env` under `PLATFORM_RETAIL_…` names.

---

## Where the values go on the server

Product settings live in `/srv/miles/shared/products/<product>/.env` (owner `miles`, mode 0600).
Back the file up before editing.

| Copied from this page               | Finance / Pharmacy `.env`        | Retail (inside Pharmacy's `.env`)       |
| ----------------------------------- | -------------------------------- | --------------------------------------- |
| Sign-in client: Client ID           | `PLATFORM_SIGN_IN_CLIENT_ID`     | `PLATFORM_RETAIL_SIGN_IN_CLIENT_ID`     |
| Sign-in client: Secret              | `PLATFORM_SIGN_IN_CLIENT_SECRET` | `PLATFORM_RETAIL_SIGN_IN_CLIENT_SECRET` |
| Service client: Client ID           | `PLATFORM_SERVICE_CLIENT_ID`     | `PLATFORM_RETAIL_SERVICE_CLIENT_ID`     |
| Service client: Secret              | `PLATFORM_SERVICE_CLIENT_SECRET` | `PLATFORM_RETAIL_SERVICE_CLIENT_SECRET` |
| Webhook signing secret (`whsec_…`)  | `PLATFORM_WEBHOOK_SECRET`        | `PLATFORM_RETAIL_WEBHOOK_SECRET`        |

The other `PLATFORM_*` lines (`PLATFORM_APPLICATION`, `PLATFORM_ROOT_DOMAIN`,
`PLATFORM_ISSUER`, `PLATFORM_HOSTED`, `PLATFORM_RETAIL_PATH`) are already set; leave them.

The products cache their configuration, so after editing:

```bash
cd /srv/miles/current/finance-app      # or pharmacy-app
sudo -u miles php artisan config:cache
systemctl reload php8.4-fpm
systemctl restart miles-product-queue@finance   # or @pharmacy
```

The next deploy keeps these values: `.env` files live in `shared/`, outside the releases.

---

## When something goes wrong

| Symptom                                                    | Likely cause and fix                                                                                         |
| ---------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| Product page shows a server error (500)                    | Its `.env` is missing a value, or `config:cache` was not run after editing.                                    |
| Sign-in says the redirect URI is not allowed               | The per-tenant callback path does not match the workspace path, or the organization has no access to the product. |
| Webhook deliveries `failed` with HTTP 401/403              | The `.env` webhook secret differs from the one issued. Rotate the secret, update the `.env`, then Redeliver. |
| Provisioning `failed`                                      | Same secret problem, or the base URL is wrong. Fix it, then Retry.                                            |
| A secret was lost before it was copied                     | Rotate it (clients: Rotate secret; webhook: tick "Issue a new signing secret").                               |
| A secret leaked                                            | Disable the client (or rotate the webhook secret), register a new one, update the `.env`.                     |

Every action on this page is written to the audit log with the staff member who did it.
