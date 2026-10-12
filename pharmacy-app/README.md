# Pharmacy

Miles Softwares' pharmacy product, served at `{workspace}/pharmacy`. Built from
[`templates/product-app`](../templates/product-app/README.md) and connected to the platform through
[`miles/platform-client`](../packages/platform-client/README.md).

## Scope

| Area                    | Status   |
| ----------------------- | -------- |
| Branch selection        | done     |
| Medicines and stock     | done     |
| Purchasing & receiving  | done     |
| Dispensing / sales      | done     |
| Prescriptions           | planned  |

Roles and permissions: [`platform/catalog.json`](platform/catalog.json) (Pharmacy Manager,
Pharmacist, Cashier, Storekeeper). Operational permissions can be scoped to a branch; a member
works in one branch at a time, chosen from the branches where they hold a pharmacy role.

## Develop

```bash
composer setup        # first time: install, .env, key, migrate, build
composer dev          # php artisan serve on :8400, queue, Vite
composer ci:check     # format, lint, types, Pint, PHPStan, Pest
```

Local platform: `cd ../central-app && php artisan platform:local-product pharmacy-app
--url=http://127.0.0.1:8400 --entitle=<workspace-slug>`, then open
`http://<workspace-slug>.milessoftwares.local/pharmacy`.
