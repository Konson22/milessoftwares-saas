# miles/platform-client

Connects a Laravel product (Pharmacy, Retail, ...) to the Miles Softwares platform. It implements
the contract in [docs/integration.md](../../docs/integration.md) so products don't each re-implement
the security-sensitive parts.

| You get                                     | How                                                                 |
| ------------------------------------------- | ------------------------------------------------------------------- |
| Sign-in (OIDC code + PKCE, state, nonce)    | `GET /{path}/auth/login`, `GET /{path}/auth/callback`, `POST /{path}/logout` (named `platform.*`) |
| The organization from the workspace host    | middleware `platform.tenant` → `CurrentTenant`                      |
| Access gate on every request                | middleware `platform.access` (cached ≤ 60 s, dropped by webhooks)   |
| Catalog permissions in `can()` / `@can`     | `$user->can('pharmacy.dispense')`; scopes via `CurrentAccess`       |
| Tenant-owned models, fail closed            | `use BelongsToTenant;` (needs an `organization_id` uuid column)     |
| Signed, idempotent webhooks                 | `POST /{path}/platform/webhooks` → `PlatformEventReceived` event    |
| Signed, idempotent provisioning             | `POST /{path}/platform/tenants` → your `ProvisionsTenants` binding  |
| Event tokens for Finance/HR, health reports | `PlatformApi::eventToken()`, `PlatformApi::reportHealth()`          |

`{path}` is the product's path on workspace hosts (default: the application code).

## Install

```jsonc
// composer.json of the product
"repositories": [
    { "type": "path", "url": "../packages/platform-client" },
    { "type": "path", "url": "../packages/shared-module-kit" }
],
"require": { "miles/platform-client": "^0.1" }
```

`php artisan migrate` creates `platform_webhook_events` and `platform_provisioned_tenants`.

The product's `users` table mirrors platform users: a **uuid primary key that is the platform
subject**, plus `name` and `email`. No passwords. Sign-in creates and updates the row.

## Configure (`.env`)

```dotenv
PLATFORM_APPLICATION=pharmacy
PLATFORM_PATH=                       # optional; defaults to PLATFORM_APPLICATION
PLATFORM_ROOT_DOMAIN=milessoftwares.com
PLATFORM_ISSUER=https://auth.milessoftwares.com
PLATFORM_BACKCHANNEL_URL=            # optional internal address of the auth host
PLATFORM_SIGN_IN_CLIENT_ID=
PLATFORM_SIGN_IN_CLIENT_SECRET=
PLATFORM_SERVICE_CLIENT_ID=          # scopes: access.read (+ events.issue, health.write)
PLATFORM_SERVICE_CLIENT_SECRET=
PLATFORM_WEBHOOK_SECRET=whsec_...
```

All values come from admin → Applications → _product_ → Integration. Register there:

- tenant callback path: `/pharmacy/auth/callback`
- webhook endpoint: `<internal URL>/pharmacy/platform/webhooks`
- provisioning base URL: `<internal URL>/pharmacy/platform` (the platform calls `.../tenants`)

## Use

```php
// routes/web.php: every product page under the same path as this package
Route::prefix(app(\Miles\PlatformClient\PlatformConfig::class)->path())
    ->middleware(['web', 'platform'])
    ->group(function () {
        Route::get('/', DashboardController::class)->name('home');
        Route::post('dispensings', [DispensingController::class, 'store'])
            ->can('pharmacy.dispense');
    });

// Tenant-owned data
class Prescription extends Model
{
    use \Miles\PlatformClient\Tenancy\BelongsToTenant;
}

// Branch-scoped roles: check the record's branch in the backend
abort_unless(app(CurrentAccess::class)->get()?->allowsIn('pharmacy.dispense', branchId: $sale->branch_id), 403);

// Setup for a newly entitled organization (runs inside the tenant, in a transaction)
$this->app->bind(ProvisionsTenants::class, CreateDefaultPharmacy::class);
```

Queued jobs and console commands have no request: wrap tenant work in
`app(CurrentTenant::class)->run($organization, fn () => ...)`.

## Develop

```bash
composer install
composer ci          # pint --test, phpstan (level 7), pest
```

Tests run against a fake platform that issues real RS256 id_tokens and a JWKS
(`tests/Fixtures/FakePlatform.php`).
