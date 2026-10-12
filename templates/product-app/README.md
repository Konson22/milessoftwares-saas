# Product template ("blueprint")

The starting point for every Miles Softwares product (Pharmacy, Retail, School, ...): Laravel 13 +
Inertia + React, connected to the platform through
[`miles/platform-client`](../../packages/platform-client/README.md).

```bash
php templates/new-product.php pharmacy "Pharmacy" 8400   # creates pharmacy-app/ and prints the next steps
```

The script renames every `blueprint` (code, URL path, permissions, cookie, database) and the
display name, and leaves this folder untouched. Change the template here when every future product
should get the change.

## What a product gets

- **Lives at `{workspace}/blueprint`.** Every route is registered under the path
  (`routes/web.php`); the web server passes it through unchanged. Built assets go to
  `public/blueprint/build`.
- **Platform sign-in**, no passwords. `users` mirrors platform users (uuid = platform subject).
- **Every page behind `platform`**: the workspace comes from the host, the member's platform
  access is checked on every request, catalog permissions work with `->can(...)` and `@can`.
- **Tenant-owned data** with `BelongsToTenant` (`app/Models/Record.php`), fail closed.
- **Provisioning**: `App\Platform\SetUpWorkspace` prepares each newly entitled organization
  (`workspaces` row with its currency and time zone).
- **The platform's browser policy** (CSP with nonces, frame denial) and its look (Tailwind theme,
  UI kit, fonts), with the workspace header in `resources/js/layouts/product-layout.tsx`.
- **Tests** with `InteractsWithPlatform`: `actingAsMember('acme', ['blueprint.records.view'])`, no
  network.

`Record` and the records pages are an example: replace them with the product's own features.

## Develop

```bash
composer setup        # install, .env, key, migrate, build
composer dev          # php artisan serve on :8499, queue, Vite
composer ci:check     # format, lint, types, Pint, PHPStan, Pest
```

Catalog (roles and permissions): `platform/catalog.json`. Register with the local platform:
`cd ../../central-app && php artisan platform:local-product templates/product-app --url=http://127.0.0.1:8499`.
