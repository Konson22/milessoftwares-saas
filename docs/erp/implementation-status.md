# Implementation status

| Phase | Status |
| --- | --- |
| 0 Audit | Done 2026-10-16 |
| 1.1 Product catalog foundation | Done 2026-10-16 (`88eb513`) |
| 1.2 Products on the site, plans sell products | Done 2026-10-16 |
| 1.3 – 2 | Not started |
| 3.1 Pharmacy code into module namespaces | Done 2026-10-16 |
| 3.2 Shared item master | Done 2026-10-16 |
| 3.3 Units and packaging | Done 2026-10-17 |
| 3.4 – 11 | Not started |

Decisions accepted 2026-10-16: D1 → `docs/adr/0003-erp-workspace-application.md`,
D2 → `docs/adr/0004-companies-as-organizations.md`.

## Phase 1.1 — 2026-10-16 (local, Windows)

**Implemented** (`central-app`, new module `App\Modules\Products`):
- Migration `2026_10_16_100000_create_erp_product_tables` (additive): `erp_products`,
  `erp_product_applications`, CHECKs on code/status/requirement, application delete restricted.
- `ProductStatus` enum with release rule; `ErpProduct` model; `ProductCatalog` (validated,
  idempotent sync); `ProductAccess` (products an organization holds, derived from entitlements);
  `platform:erp-products` command; `database/erp-products.json` (5 products).

**Reused:** `applications`, plans/subscriptions/entitlements unchanged; `TenantContext`,
`Entitlement::current()`.

**Not changed:** public site, admin UI, any product app, the uncommitted public-site work.

**Tests executed:**
- `tests/Feature/Products/ProductCatalogTest.php`: 29 passed (50 assertions).
- Full central suite: 564 passed, 1 skipped (pre-existing CREATEROLE skip); baseline before the
  slice 535 passed, 1 skipped.
- Pint pass; PHPStan 0 errors.
- Migration up → rollback → up on `miles_platform_test`: all DONE.
- Mutation checks: product access with "any" instead of "every" required application fails 5
  tests; removing the release-status check fails 3 tests.
- Frontend untouched; no build needed.

**Known limitations:** products not yet visible anywhere in the UI; no plan ↔ product link; the
dev database has not been migrated or synced (run `php artisan migrate` and
`php artisan platform:erp-products` when ready).

**Remaining risks:** see `architecture-audit.md` §6 (R1–R13).

**Next-phase dependencies:** commit the pending public-site work (done, `9bb5d1b`); decisions D1
and D2 (accepted).

## Phase 1.2 — 2026-10-16 (local, Windows)

**Implemented** (`central-app`):
- Migration `2026_10_16_110000_add_erp_product_to_plans` (additive): nullable
  `plans.erp_product_id`; deleting a product that has plans is refused.
- `ProductPlans` rule: a plan selling a product must include every required application, nothing
  outside the product, and may be active only for a pilot or available product. Enforced in admin
  plan create/update and by `platform:erp-products` (a catalog change that would break a
  non-retired plan is refused with a `plans` error).
- Admin: read-only **Products** page (`/products`, `plans.manage`); ERP product select on plans.
- Public site: "ERP products" section on the home page, in the nav and footer; product pages at
  `/erp/{code}` (applications shown as included / optional add-on, plans for the product);
  badges Pilot / Coming soon; retired products 404; sitemap entries. Plans that sell a product
  name it and lead to Contact instead of a single-application trial. `/products/{code}` URLs
  unchanged.

**Tests executed:**
- `tests/Feature/Products`: 45 passed (144 assertions); with the billing admin tests: 56 passed.
- Full central suite: 580 passed, 1 skipped (pre-existing).
- Vitest 35 passed (accessibility checks now cover the ERP product page and product plans);
  `vp check` clean; `tsc` clean; Pint; PHPStan 0 errors; `npm run build` OK.
- Migrations: rollback 2 steps → migrate on `miles_platform_test`: DONE.
- Mutation checks: removing the catalog's plan guard fails 2 tests; skipping the admin coverage
  check fails 3 tests.

**Known limitations:**
- No self-serve product trial: a trial covers one application and a product needs several, so
  every product call to action goes to Contact.
- Not checked live in a browser; the dev database has not been migrated or synced. To see it:
  `php artisan migrate`, register the pharmacy and finance applications, then
  `php artisan platform:erp-products`.
- The contact form does not record which product the visitor asked about.

**Next:** Phase 1.3, subscription lapse behaviour (grace / read-only window), then Phase 2.

## Phase 3.1 — 2026-10-16 (local, Windows)

Taken ahead of 1.3 and 2 at the owner's request: the code reorganisation ADR-0003 schedules for
Phase 3, with no behaviour change.

**Implemented** (`pharmacy-app`, branch `erp/phase-3-pharmacy-modules`):
- 41 classes moved (git history kept) into `app/Modules/{MasterData, Inventory, Procurement,
  Sales, Pharmacy}` (`Models`, `Enums`, `Actions`, `Http/Controllers`); branch context, money and
  document numbers into `app/Support`; branch, home and report pages stay in `app/Http`.
- `VoidSale` no longer calls Pharmacy: it dispatches `Sales\Events\SaleVoided` inside its
  transaction; `Pharmacy\Listeners\ReturnVoidedSaleToPrescription` reopens the prescription.
- `tests/Unit/ArchitectureTest.php` (10 rules) holds the boundaries. One listed exception:
  shared modules may use `Pharmacy\Models\Medicine`, still the item every batch and line points at.
- Medicine's factory is linked explicitly (`#[UseFactory]`, `$model`): the name guess no longer
  works outside `App\Models`.

**Unchanged:** tables and migrations, routes, route names, permission keys, Inertia pages,
events sent to Finance.

**Tests executed:**
- Pharmacy `composer ci:check`: Pest 84 passed, 1 skipped (PostgreSQL-only, as before); PHPStan
  0 errors; Pint; `vp check`; `tsc`. Before the move: 74 passed, 1 skipped (the 10 new ones are
  architecture rules).
- Mutation checks: unregistering the `SaleVoided` listener fails 2 prescription tests; a Sales
  class using `Pharmacy\Actions\Prescriptions` fails the architecture test.
- Live: the running app boots, all 40 routes resolve to the module controllers, guests are sent
  to sign in.

**Found on the way:** Pest 5.3 lets violations through when `arch()` gets an array of targets
together with `ignoring()`; the rules use one target each.

**Known limitations / next slices:**
- Shared item master (Inventory item with units/packaging; Medicine becomes Pharmacy's details on
  it) removes the exception above. Needs a data migration.
- `sales.prescription_id` still references a Pharmacy table from a Sales table.
- Customers (Sales) and units/conversions (MasterData) are not built yet (Phase 3 master data).
- Permission keys keep the `pharmacy.` prefix (`pharmacy.sales.create`…); renaming them is a
  catalog change on the platform and is left for when Retail joins.

## Phase 3.2 — 2026-10-16 (local, Windows)

The shared item master: stock, purchasing and sales point at an Inventory item, and a medicine
is Pharmacy's details of one. Removes the one exception the Phase 3.1 boundaries allowed.

**Implemented** (`pharmacy-app`, branch `erp/phase-3-pharmacy-modules`):
- `items` (Inventory): kind, name as printed on documents, alias (generic name), unit, barcode,
  sell price, reorder level, `restricted` (sold only with an authorisation: prescription-only),
  `controlled`, active. `Inventory\Models\Item` with its own factory.
- `medicines` keeps name, form and strength; its id is its item's id (foreign key). `Medicine`
  reads and writes the moved attributes through its item (`ON_ITEM`) and saves item then medicine
  in one transaction, keeping the item's name equal to `label()`. Pharmacy code and its pages
  did not change apart from queries on moved columns (`active()`, `whereRelation`).
- `medicine_id` → `item_id` on batches, stock movements and purchase order, goods receipt, sale
  and supplier return lines, with foreign keys to `items`. `prescription_items` keeps
  `medicine_id` (a Pharmacy table).
- `Stock`, `Sell`, `ReceiveGoods`, `ReturnGoods`, `Purchasing`, their controllers and the home and
  report pages take or read `Item`. `Sell(authorized: …)` replaces `prescribed:`; `Prescriptions`
  passes the prescription's medicine ids.
- Order, receipt and sale forms post `item_id` (3 pages, 8 lines). Page props kept their names.
- Architecture test: the Medicine exception is gone; home and report pages may not use Pharmacy.

**Data migration** `2026_10_16_100000_create_items_and_move_stock_onto_them`: every medicine
becomes an item with the same id, so no reference changes value. `down()` puts the columns back
from the items; it stops if an item that is not a medicine is referenced.

**Tests executed:**
- `composer ci:check`: Pest 88 passed, 1 skipped (PostgreSQL-only); PHPStan 0; Pint; `vp check`;
  `tsc`. Before: 84 passed. New: item written with the medicine, search through the item,
  migration up → down → up on old-shape rows.
- Full suite on a scratch PostgreSQL database: 89 passed (the PostgreSQL-only test included).
- Local PostgreSQL: `migrate`, `migrate:rollback --step=1`, `migrate` (no pharmacy rows there).
- Mutation: a Sales class using `Pharmacy\Models\Medicine` fails the architecture test.

**Known limitations / next slices:**
- Units and packaging: done in 3.3.
- The "needs a verified prescription" message still lives in `Sell`.
- Page props still say `medicines` / `medicine`; the stock page shows the item's `controlled`.
- `sales.prescription_id` still references a Pharmacy table from a Sales table.
- Customers (Sales) and units/conversions (MasterData) are not built yet.

## Phase 3.3 — 2026-10-17 (local, Windows)

Units and packaging: stock is counted in the item's unit (a capsule); an item can be bought in
packs (a box of 30).

**Implemented** (`pharmacy-app`, branch `erp/phase-3-pharmacy-modules`):
- `item_packs` (Inventory): name and units per item; `ItemPacks::sync()` (matched by name;
  refuses the unit's own name, duplicates, fewer than 2) and `ItemPacks::find()` (never another
  item's pack). Edited on the medicine form ("Packs it is bought in").
- Purchase order and goods receipt lines count what the supplier sold: quantity and cost per
  pack, with `pack_name` and `units_per_pack` copied onto the line, so editing a pack never
  rewrites a document. Old lines are units (`units_per_pack` 1) and read as before.
- Receiving: stock gets packs × units, at the pack cost shared over its units (rounded to the
  minor unit); Finance is billed exactly packs × pack cost, the pack named in the line
  description. Against an order, a line uses the order line's pack and is counted in it.
- Supplier returns stay in units (loose capsules from a box can go back). Credits come out of
  the receipt line's bill cumulatively, so returns in parts add up to the bill exactly.
- Pages: order and receipt forms pick "bought as" per line (`PackSelect`); order, receipt and
  return pages show the pack.

**Tests executed:**
- `composer ci:check`: Pest 95 passed, 1 skipped (PostgreSQL-only); PHPStan 0; Pint; `vp check`;
  `tsc`. Before: 88. New `PacksTest` (7): packs on the medicine, refused packs, order and
  receipt in boxes with the bill exact, packs without an order, loose returns credited exactly.
- Full suite on a scratch PostgreSQL database: 96 passed.
- Local PostgreSQL: `migrate`, `migrate:rollback --step=1`, `migrate`.
- Mutations: stocking packs instead of units fails 3 tests; crediting each return on its own
  (10.02 back for a 10.01 box) fails the return test.

**Found on the way:** the 3.2 migration test rolled back `items` while `item_packs` pointed at
it; SQLite let it through, PostgreSQL did not. The test now rolls back in order.

**Known limitations / next slices:**
- The counter (POS) sells units only; selling a whole pack at a pack price is not built.
- A receipt against an order line cannot switch to another pack than the order's.
- Batch cost from a pack is rounded per unit; Finance's bill is exact, stock value may differ
  by under a minor unit per unit.
- Customers (Sales) are not built yet; warehouses/locations remain Phase 5.
