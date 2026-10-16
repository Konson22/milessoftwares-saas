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
| Prescriptions           | done     |

Roles and permissions: [`platform/catalog.json`](platform/catalog.json) (Pharmacy Manager,
Pharmacist, Cashier, Storekeeper). Operational permissions can be scoped to a branch; a member
works in one branch at a time, chosen from the branches where they hold a pharmacy role.

## Code layout

This app is growing into the ERP workspace application
([ADR-0003](../docs/adr/0003-erp-workspace-application.md)): shared business modules, with
Pharmacy as the industry module on top.

| Folder                    | Holds                                                      |
| ------------------------- | ---------------------------------------------------------- |
| `app/Modules/MasterData`  | suppliers (customers and units later)                      |
| `app/Modules/Inventory`   | batches, the append-only stock movements, FEFO (`Stock`)   |
| `app/Modules/Procurement` | purchase orders, goods receipts, supplier returns          |
| `app/Modules/Sales`       | counter sales and voids                                    |
| `app/Modules/Pharmacy`    | medicines, prescriptions, the controlled register          |
| `app/Support`             | the working branch, money, document numbers (every module) |
| `app/Http`                | branch choice, home and reports (pages that span modules)  |

Each module has `Models`, `Enums`, `Actions` and `Http/Controllers`. Rules, enforced by
`tests/Unit/ArchitectureTest.php`:

- Shared modules never use Pharmacy. They raise events instead and Pharmacy listens (a voided
  sale reopens its prescription through `SaleVoided`). Listeners run in the raising transaction,
  never queued.
- One exception until a shared item master exists: `Pharmacy\Models\Medicine` is still the item
  that batches, order lines and sale lines point at.
- Inventory knows nothing of Procurement or Sales; those two stay apart; master data depends on
  no other module; `app/Support` depends on none.

Tables, routes, route names and permission keys did not change with the move.

## Develop

```bash
composer setup        # first time: install, .env, key, migrate, build
composer dev          # php artisan serve on :8400, queue, Vite
composer ci:check     # format, lint, types, Pint, PHPStan, Pest
```

Local platform: `cd ../central-app && php artisan platform:local-product pharmacy-app
--url=http://127.0.0.1:8400 --entitle=<workspace-slug>`, then open
`http://<workspace-slug>.milessoftwares.local/pharmacy`.
