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
| 3.4 Counter: one sale per submission, discounts, receipts | Done 2026-10-18 |
| 3.5 Cashier tills and reconciliation | Done 2026-10-18 |
| 3.6 Partial sales returns | Done 2026-10-18 |
| 3.7 Batch holds and approved disposals | Done 2026-10-18 |
| 3.8 Profitability, purchases and cashier reports | Done 2026-10-18 |
| 3.9 Categories, storage and selling packs | Done 2026-10-18 |
| 3.10 Stock locations within a branch | Done 2026-10-18 |
| 3.11 Stock write-offs and cash over/short in Finance | Done 2026-10-18 |
| 3.12 Deliveries received onto a location | Done 2026-10-18 |
| 3.13 CSV export of reports | Done 2026-10-18 |
| 3.14 Stock transfers between branches | Done 2026-10-18 |
| 4 – 11 | Not started |
| HR H0 Foundation (structure API, `hr-app`) | Done 2026-10-19 |
| HR H1 Staff records | Done 2026-10-19 |
| HR H2 Documents | Done 2026-10-19 |
| HR H3 Leave | Done 2026-10-23 |
| Finance F1 Periods and close | Done 2026-10-20 |
| Finance F2 Reports | Done 2026-10-20 |
| Finance F3 Bank reconciliation | Done 2026-10-20 |
| Finance F4 Tax | Done 2026-10-20 |
| Finance F5 Dimensions | Done 2026-10-20 |
| Finance F6 Multicurrency | Done 2026-10-20 |
| Finance F7 Budgets | Done 2026-10-20 |
| Finance F8 Fixed assets | Done 2026-10-20 |

Decisions accepted 2026-10-16: D1 → `docs/adr/0003-erp-workspace-application.md`,
D2 → `docs/adr/0004-companies-as-organizations.md`. Accepted 2026-10-19: D3 →
`docs/adr/0005-hr-and-payroll-application.md` (HR phases: `hr-roadmap.md`).

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

## Phase 3.4 — 2026-10-18 (local, Windows)

The counter, hardened for the pilot: a sale is recorded once however often it is sent, discounts
are validated, and the customer gets a printed receipt.

**Implemented** (`pharmacy-app`, branch `erp/phase-3-pharmacy-modules`):
- Migration `2026_10_18_100000_add_submission_and_discounts_to_sales` (additive): `sales.submission_id`
  (unique per organization), `sales.discount_minor`, `sale_lines.discount_minor`.
- `Sell`: a sale sent with a `submission_id` already used returns that sale (checked first, and
  again after a unique-key clash with a request running alongside), so stock, outbox and number
  are taken once. The counter sends a new key per basket.
- Discounts per line in percent: only with the new permission `pharmacy.sales.discount` (Pharmacy
  Manager; catalog 1.2.0) and at most `config('pharmacy.max_discount_percent')` (env
  `PHARMACY_MAX_DISCOUNT_PERCENT`, default 20). Shared across the batches of a line in proportion;
  Finance gets the net amount per line (revenue after discount), cost unchanged.
- Receipt page `sales/{sale}/receipt`: one line per medicine, discount, payment and change, sized for
  an 80 mm printer; printing shows only the receipt. The sale page links to it.

**Tests executed:**
- Pharmacy: Pest 103 passed, 1 skipped; PHPStan 0; Pint; `vp check`; `tsc`. New `CounterTest` (8).
- Local PostgreSQL: `migrate`, `migrate:rollback --step=1`, `migrate`.
- Mutations: never finding the earlier submission fails the double-submit test; giving the whole
  discount to the last batch fails the discount test.

**Known limitations:** the discount limit is one setting for the whole installation, not per
workspace or role; discounts are percent per line only (no amount off, no whole-basket discount).

## Phase 3.5 — 2026-10-18 (local, Windows)

Cashier sessions: every counter sale is taken into a till, and each till is counted at closing
against what it should hold.

**Implemented** (`pharmacy-app`, branch `erp/phase-3-pharmacy-modules`):
- Migration `2026_10_18_110000_create_till_sessions` (additive): `till_sessions` (number `T-00001`,
  opening float, expected and counted cash fixed at closing, note), one open till per person per
  branch (partial unique index); `sales.till_session_id` and `sales.void_till_session_id`.
- `Sales\Actions\Tills`: open, close (a count that differs needs a note), summary (takings per
  method, cash given back, cash it should hold = float + cash taken − cash refunds).
- `Sell` refuses without the seller's open till (counter and prescription dispensing alike).
  `VoidSale` gives cash back from the voider's open till and refuses a cash void without one.
- Pages `tills` (open yours; the day's tills with expected, counted, over/short) and `tills/{till}`
  (breakdown, count and close). The counter asks to open a till first. Menu: Counter → Tills.
- Permission `pharmacy.tills.manage` (Pharmacy Manager; catalog 1.3.0): see and close every
  cashier's till in the branch (the cashier reconciliation). `branch.can` takes several
  permissions (any one).

**Tests executed:**
- Pharmacy: Pest 108 passed, 1 skipped; PHPStan 0; Pint; `vp check`; `tsc`. New `TillTest` (5);
  existing tests open a till before selling.
- Local PostgreSQL: `migrate`, `migrate:rollback --step=1`, `migrate`.
- Mutation: leaving refunds out of the expected cash fails the till test.

**Known limitations:** over/short is recorded in the pharmacy only; it is not posted to Finance
(no event for cash differences yet). Cash moved into or out of a drawer during the day (a
pay-out, a safe drop) is not recorded.

## Phase 3.6 — 2026-10-18 (local, Windows)

Partial returns: part of a sale comes back and is refunded, the rest of the sale stands.

**Implemented** (`pharmacy-app`, `finance-app`, `contracts`):
- Pharmacy migration `2026_10_18_120000_create_sale_returns` (additive): `sale_returns` (number
  `R-000001`, reason, refund method, till) and `sale_return_lines` (quantity, amount, cost,
  restocked).
- `Sales\Actions\ReturnSale`: up to what is left on each line; the refund is worked out on the
  line's total (discount included) so returns in parts add up to it exactly; cash comes from the
  open till of whoever takes the return; units fit to sell go back into their batch
  (`sale_return` movement). A sale partly returned can no longer be voided.
- Till summary, sales-day takings, home takings and the reports count returns off revenue, and
  restocked goods off the cost of goods sold. The sale page shows what came back and, for those
  with `pharmacy.sales.void` (now "Void sales and take returns", catalog 1.4.0), a return form.
- Contract `finance.sales_return.issued` v1. Finance: Dr revenue (+ tax) / Cr receivable, the
  cost of restocked goods Dr inventory / Cr cost of goods sold, and the refund Dr receivable /
  Cr cash/bank as a refund payment. Migration `2026_10_18_100000_add_credited_to_invoices`:
  invoices keep what returns took off (`credited_minor`); owed = total − returned − paid.

**Tests executed:**
- Pharmacy: Pest 114 passed, 1 skipped; PHPStan 0; Pint; `vp check`; `tsc`. New `SaleReturnTest` (6).
- Finance: Pest 47 passed, 1 skipped (2 new in `EventsTest`); PHPStan 0; Pint; `tsc`.
- Local PostgreSQL (both apps): `migrate`, `migrate:rollback --step=1`, `migrate`.
- Mutation: dropping what was already returned from the cumulative refund fails the return test.

**Known limitations:** a refund is given the way the sale was paid; there is no exchange (swap
for another item) or store credit.

## Phase 3.7 — 2026-10-18 (local, Windows)

Batches can be held back from sale, and stock is destroyed only with a second person's approval.

**Implemented** (`pharmacy-app`):
- Migration `2026_10_18_130000_add_batch_holds_and_stock_disposals` (additive): `batches.status`
  (available | quarantined | recalled, default available), hold reason, who and when;
  `stock_disposals` (number `D-00001`, batch, quantity, reason, note, pending → approved | rejected,
  requester and decider).
- `Inventory\Actions\BatchHolds`: quarantine (releasable) and recall (final: the batch leaves by a
  supplier return or a disposal). FEFO, the counter, prescriptions, home and stock pages count only
  available batches; the report values stock on hold separately; the stock list has "On hold".
- `Inventory\Actions\Disposals`: request (no more than is on hand less what other requests ask
  for), approve (stock leaves the batch as a `disposal` movement, in the controlled register too),
  reject (with a reason). The requester can never decide their own disposal. Negative adjustments
  for damaged or expired stock are refused: they go through a disposal.
- Pages: stock item page (status, release, hold form, disposal request, pending list) and
  `disposals` (waiting / approved / turned down, approve or turn down). Menu: Inventory → Disposals.
- Permissions (catalog 1.5.0): `pharmacy.stock.hold` (manager, pharmacist), `pharmacy.stock.dispose`
  (manager); `pharmacy.stock.adjust` renamed "Adjust stock (count) and ask for disposals"; SoD
  warning for adjust + dispose.

**Tests executed:**
- Pharmacy: Pest 121 passed, 1 skipped; PHPStan 0; Pint; `vp check`; `tsc`. New
  `BatchHoldAndDisposalTest` (7).
- Local PostgreSQL: `migrate`, `migrate:rollback --step=1`, `migrate`.
- Mutation: selling from held batches, and letting a requester approve their own disposal, each
  fail a test.

**Known limitations:** disposals and adjustments are not posted to Finance (inventory there drifts
from the pharmacy by write-offs until an inventory write-off event exists). Units awaiting disposal
stay sellable unless the batch is quarantined.

## Phase 3.8 — 2026-10-18 (local, Windows)

The branch reports gain profitability, purchase history and cashier reconciliation.

**Implemented** (`pharmacy-app`):
- `App\Support\ReportPeriod`: one period rule for all reports (default month so far, at most a
  year); the overview uses it.
- `reports/profitability`: per medicine, sold less returned, discounts, revenue, cost (restocked
  returns off), profit and margin; totals agree with the overview.
- `reports/purchases`: per supplier (received less sent back), per medicine (units, spend, average
  and range of unit cost per base unit), and the period's deliveries. Supplier balances stay in
  Finance payables.
- `reports/cashiers`: every till opened in the period (taken, cash refunds, expected, counted,
  over/short, note) and per cashier totals with over and short.
- Shared report header (tabs keep the period, print button). All need `pharmacy.reports.view`.

**Tests executed:**
- Pharmacy: Pest 127 passed, 1 skipped; PHPStan 0; Pint; `vp check`; `tsc`. New
  `AnalysisReportsTest` (6), with profitability reconciled to the overview.
- Mutation: leaving restocked returns in the cost fails the profitability test.

**Known limitations:** no export (CSV/PDF other than the browser's print); reports are per branch.

## Phase 3.9 — 2026-10-18 (local, Windows)

Medicines get a category and a storage condition, and the counter sells packs.

**Implemented** (`pharmacy-app`):
- Migration `2026_10_18_140000_add_categories_storage_and_selling_packs` (additive):
  `items.category` (indexed) and `items.storage` (room | cool | refrigerated | frozen, default
  room), on the shared item so every module can use them; `item_packs.sell_price_minor`
  (nullable: the pack's units at the unit price); `sale_lines.pack_name`, `units_per_pack`,
  `pack_price_minor` (the pack as sold).
- Medicine form: category (suggesting those in use, one spelling per category), storage, a price
  per pack. Medicines list: category column and filter, storage badge.
- `Sell`: a line may name one of the item's packs; quantity is then packs, stock is taken in units
  (FEFO), the price and discount are shared over the batches in proportion. Finance gets the units
  and "(2 box of 10)" in the description. Receipts show packs; returns stay in units at what they
  were sold for.
- Counter: choose unit or pack per line (packs larger than the stock are disabled), search by
  category, storage badge.

**Tests executed:**
- Pharmacy: Pest 132 passed, 1 skipped; PHPStan 0; Pint; `vp check`; `tsc`. New
  `CategoriesAndPackSalesTest` (5).
- Local PostgreSQL: `migrate`, `migrate:rollback --step=1`, `migrate`.
- Mutation: pricing pack lines by the unit price fails the pack sale test.

## Phase 3.10 — 2026-10-18 (local, Windows)

Where stock is kept within a branch.

**Implemented** (`pharmacy-app`):
- Migration `2026_10_18_150000_create_stock_locations` (additive): `stock_locations` (per branch,
  unique name, in use or not), `batch_locations` (units of a batch at a location, never negative),
  `stock_location_moves` (who moved how many from where to where).
- `Inventory\Actions\Locations`: create, rename, take out of use (only when empty), move units
  between locations or to and from "not put away". Moves never change what is on hand.
- `Stock`: opening stock can go straight to a location; every decrease (sale, disposal, supplier
  return, adjustment) comes off "not put away" first, then off the locations in name order, so the
  locations never hold more than the batch.
- Pages: `locations` (each location's batches and units, what is not put away, add and rename) and
  `locations/{location}` (its contents as a printable count sheet). The stock item page shows
  where each batch is and moves it. Menu: Inventory → Locations. Managing and moving need
  `pharmacy.stock.adjust`; viewing `pharmacy.stock.view`.

**Tests executed:**
- Pharmacy: Pest 137 passed, 1 skipped; PHPStan 0; Pint; `vp check`; `tsc`. New
  `StockLocationsTest` (5).
- Local PostgreSQL: `migrate`, `migrate:rollback --step=1`, `migrate`.
- Mutation: not taking leaving stock off the locations fails the location test.

**Known limitations:** the counter does not pick a location (stock leaves "not put away" first). Locations are within a
branch; moving stock between branches came in Phase 3.14.

## Phase 3.11 — 2026-10-18 (local, Windows)

Stock and cash changes outside buying and selling reach the ledger.

**Implemented:**
- Contracts: `finance.stock.adjusted.v1` (kind `opening`, `count` or `write_off`, signed amount at
  cost, lines) and `finance.cash.counted.v1` (expected, counted, difference; never zero).
- `finance-app`: system accounts 5100 "Stock losses and write-offs" and 6800 "Cash over and short"
  (created by `ChartOfAccounts::ensure`). `PostEvent`: opening stock Dr inventory / Cr owner's
  equity; a count or write-off loss Dr stock losses / Cr inventory (a gain the reverse); a short
  till Dr cash over and short / Cr cash (over the reverse).
- `pharmacy-app`: `Stock` sends opening stock, adjustments (`count`) and approved disposals
  (`write_off`, under the disposal number) at the batch's unit cost. `Tills::close` sends the
  difference when the count is not what was expected.

**Tests executed:**
- Finance: Pest 49 passed, 1 skipped (2 new in `EventsTest`); PHPStan 0; Pint.
- Pharmacy: Pest 140 passed, 1 skipped; PHPStan 0; Pint. New `FinanceAdjustmentsTest` (3).

**Known limitations:** stock and tills recorded before this phase are not sent retroactively. If an
organisation already uses code 5100 or 6800 for an account of its own, the system account is not
created and posting these events fails until the code is freed.

## Phase 3.12 — 2026-10-18 (local, Windows)

**Implemented** (`pharmacy-app`): each line of a goods receipt can name a location of the branch
in use ("Put away at"); `ReceiveGoods` refuses a location of another branch or out of use. Left
empty, the stock stays "not put away" as before.

**Tests executed:** Pharmacy Pest 141 passed, 1 skipped (1 new in `StockLocationsTest`); PHPStan 0;
Pint; `vp check`; `tsc`.

## Phase 3.13 — 2026-10-18 (local, Windows)

**Implemented** (`pharmacy-app`): every report table downloads as CSV (`?export=<table>` on the
report, links in the report header): overview (sales by day, by payment, top medicines),
profitability, purchases (by supplier, by medicine, deliveries), cashiers (by cashier, tills).
Same rows and period as the page; amounts in major units; UTF-8 with a byte-order mark; text that
a spreadsheet would run as a formula is written as text (`App\Support\CsvExport`).

**Tests executed:** Pharmacy Pest 143 passed, 1 skipped (2 new in `AnalysisReportsTest`); PHPStan 0;
Pint; `vp check`; `tsc`.

**Known limitations:** exports carry the same row caps as the pages (500 tills, 200 deliveries,
top 10 medicines).

## Phase 3.14 — 2026-10-18 (local, Windows)

Stock sent from one branch to another.

**Implemented** (`pharmacy-app`):
- Migration `2026_10_18_160000_create_stock_transfers` (additive): `stock_transfers` (number `T…`,
  from and to branch, in transit → received | cancelled, who and when) and `stock_transfer_lines`
  (sending batch, its number, expiry and cost, quantity, the batch it arrived in).
- `Inventory\Actions\Transfers`: send (only batches of the branch, in date, off hold, up to what is
  on hand less pending disposals; never to the same branch), receive (into a batch of the same
  number, expiry and cost in the receiving branch, optionally onto a location), cancel (sender only,
  while in transit: the stock goes back into the batches it left). Movement types `transfer_out`
  and `transfer_in`. Nothing goes to Finance: the stock stays the organization's, at cost.
- Pages: `transfers` (coming in / sent out, in-transit first), `transfers/create`, `transfers/{id}`
  (printable delivery note; receive or cancel). Menu: Inventory → Transfers. Viewing needs
  `pharmacy.stock.view` in either branch of the transfer; sending, receiving and cancelling need
  `pharmacy.stock.adjust` in the branch doing it.

**Tests executed:**
- Pharmacy: Pest 147 passed, 1 skipped; PHPStan 0; Pint; `vp check`; `tsc`; build. New
  `StockTransferTest` (4).
- Local PostgreSQL: `migrate`, `migrate:rollback --step=1`, `migrate`.
- Mutation: not taking stock out when sending fails the transfer tests.

**Known limitations:** a transfer is received whole (no partial receipt or discrepancy note);
the sending branch cannot be changed once sent. Branch-level figures in Finance do not move with
the stock (Finance does not split inventory by branch).

## HR Phase H0 — 2026-10-19 (local, Windows)

Decision D3 ([ADR-0005](../adr/0005-hr-and-payroll-application.md)): HR and Payroll are one
separate application, `hr-app` (registry code `hr`, status `planned`); Payroll will be the
`payroll` plan feature. Phases in [`hr-roadmap.md`](hr-roadmap.md).

**Implemented:**
- `central-app`: `GET /api/v1/organizations/{id}/departments` and `.../positions` (scope
  `access.read`, only for organizations ever entitled to the caller, like branches). No member
  directory is offered to products.
- `packages/platform-client`: `PlatformApi::departments()` / `positions()` (cached per
  organization like branches), `Department` / `Position`, `EffectiveAccess::departmentsFor()`,
  `PlatformFake::department()` / `position()`.
- `hr-app` from `templates/product-app` (port 8600); catalog 1.0.0 (`platform/catalog.json`):
  seven permissions, roles Line manager / HR officer / HR manager.
- `erp-products.json`: Education and NGO list `hr` (optional); no `payroll` application.
- Ops: `ops/nginx/products/hr.conf`, `ops/php-fpm/hr.conf`, local-dev proxy for `/hr`. Not in
  the deploy scripts' default `PRODUCTS` until the server has the `hr` database and `.env`.
- `templates/new-product.php`: also rewrites `file:../../packages/` (package.json and its lock),
  so a new product links `@miles/ui` from the repository's `packages/`.

## HR Phase H1 — 2026-10-19 (local, Windows)

**Implemented** (`hr-app`, module `App\Modules\Employees`):
- Migration `2026_10_19_100000_create_employee_tables`: `employees`, `employee_personal_details`
  (one encrypted array), `employment_records` (append-only), `employment_contracts`,
  `hr_audit_log`, `document_numbers`. Children reference `(organization_id, employee_id)`.
- Actions: `Staff` (hire: number `E-00001`, hired record, first contract; work details; managers
  without loops), `Employment` (transfer / promotion / change of job from a date never before the
  latest record; new contract ends the previous the day before; leaving ends the contract and
  unassigns reports), `ManagePersonalDetails` (view and change, audited by field name).
- `StaffScope`: organization, branch and department grants decide whom a member reaches; out of
  reach is 404. A move needs `hr.employment.manage` where the person is and where they go.
- Pages: home (headcount, joiners, leavers, contracts and probations ending in 30 days),
  `employees` (search, status, branch, department), `employees/create`, `employees/{id}` (work
  details, history, contracts; edit, move, new contract, leaving), `employees/{id}/personal`.

**Tests executed:**
- Central: Pest 625 passed, 1 skipped (4 new in `Integration/StructureApiTest`); PHPStan 0; Pint.
- HR: Pest 22 passed (`EmployeesTest` 7, `EmploymentTest` 5, `PersonalDetailsTest` 2, access and
  provisioning); PHPStan 0; Pint; `vp check`; `tsc`; build.
- platform-client: Pest 96 passed; PHPStan 0; Pint.
- Local PostgreSQL (`hr`): `migrate`, `migrate:rollback --step=1`, `migrate`.
- Mutation: returning the unfiltered query from `StaffScope::apply()` fails the branch and
  department visibility tests.

**Known limitations:** personal details are encrypted with `APP_KEY` (no separate key or
rotation yet); lists stop at 500 staff (search narrows); a department grant does not reach
sub-departments; the platform's structure lists are cached for up to a minute, so a just-added
branch may take that long to appear.

## HR Phase H2 — 2026-10-19 (local, Windows)

**Implemented** (`hr-app`, module `App\Modules\Documents`):
- Migration `2026_10_20_100000_create_employee_documents_table`: `employee_documents` (category,
  title, issued and expiry dates, file metadata with SHA-256, uploaded and removed by), and
  `hr_audit_log.subject_id` (which document an entry is about).
- Private `hr_documents` disk (`storage/app/hr-documents`, `serve: false`); files stored at
  `{organization}/{employee}/{document id}`, nothing from the upload in the path. The uploaded
  name is kept, cleaned, as the download name with the extension of what the file really is.
- Upload: PDF, JPEG or PNG judged by content (`mimes`) and by name (`extensions`), up to 8 MB;
  php-fpm pool `upload_max_filesize` 9M, `post_max_size` 10M (nginx allows 10 MB).
- Access follows the staff (`hr.documents.view` / `.manage` where the employee works; out of
  reach is 404). Identity documents also need the organization-wide `hr.personal.view` /
  `.manage`; others see only that some are hidden.
- Downloads: the page links to `documents/{id}/open` (checks access), which redirects to
  `documents/{id}/download` signed for 5 minutes (`signed:relative`), which checks access again,
  logs `documents.downloaded` and sends the file as an attachment with `nosniff`, `no-store` and
  a sandboxing CSP.
- Removing deletes the file after the commit and keeps the row (who, when).
- Home: "Documents to renew", expired or expiring in 30 days, for current staff in reach.
- Backups: `ops/backup/backup-files.sh` (tar, GPG, SHA-256, upload, retention; refuses
  production without a GPG recipient) and `miles-backup-files@.{service,timer}` (02:30 UTC,
  instance `hr`); runbook section "Product files".

**Tests executed:**
- HR: Pest 28 passed (`DocumentsTest` 6 new); PHPStan 0; Pint; `vp check`; `tsc`; build.
- Local PostgreSQL (`hr`): `migrate`, `migrate:rollback --step=1`, `migrate`.
- `backup-files.sh` run locally: archive lists the tree, `sha256sum -c` OK, refuses
  `APP_ENV=production` without `BACKUP_GPG_RECIPIENT`, refuses a bad name.
- Mutations: removing `signed:relative` fails the signed-link test; skipping the identity check
  fails the identity test.

**Known limitations:** reminders are the home-page list only (no e-mail yet); files are not
encrypted at rest by the application (disk encryption and the encrypted backup cover them); no
virus scanning. The nightly logical dump (`backup.sh`) covers only `PGDATABASE`; product databases
(`hr`, also pharmacy and finance) rely on cluster-wide WAL archiving until a dump per database is added.

## Retail R0–R1 — 2026-10-19 (local, Windows)

**Implemented** (`packages/platform-client`, `pharmacy-app`, `central-app`; plan in `retail-roadmap.md`):
- R0, platform-client: one deployment serves several registry applications. `PLATFORM_HOSTED`
  lists them, with `PLATFORM_<APP>_PATH`, sign-in and service clients, and a webhook secret per
  application. `PlatformConfig::runAs()` / `application()`. Middleware `platform.app:<code>`.
  Routes, cache keys and the organization and access lookups are per application. Outbox rows
  record the application and are delivered with its clients and `source_app`. `PlatformFake` is
  application-aware.
- R0, central-app: `platform:local-product --catalog=platform/retail.json` registers a hosted
  application and writes the prefixed keys. A test imports every repository catalog.
- R1, shared engine: `items.tracks_expiry`; `expires_on` nullable on batches, goods-receipt lines
  and transfer lines (undated stock never expires and sells after dated stock); `sales.application`.
- R1, `App\Support\Industry` (config `config/industries.php`): the item kinds, permissions, pages,
  route names and discount limit of the application being served. Sales, tills, stock, home and
  branch controllers use it; `Sell` refuses another industry's items and records the application.
- R1, Retail module: `Product` (an Inventory item of kind `product`, scoped to it) and
  `ProductController` (catalogue with packs, barcode unique across all items, expiry tracking
  fixed once stocked). `routes/retail.php` at `/retail`, named `retail.*`, loaded when the
  deployment serves `retail`. Catalog `platform/retail.json` 1.0.0 (planned).
- R1, pages under `resources/js/pages/retail` with `RetailLayout`: home, products, barcode counter
  (Enter adds the scanned product), sales, receipt, tills, stock.
- Arch rules: shared modules do not use Retail; Retail and Pharmacy do not use each other.

**Tests executed:**
- pharmacy-app: Pest 172 passed, 1 skipped (`RetailTest` 13 new, `UndatedStockTest` 6 new);
  PHPStan 0; Pint; `vp check`; `tsc`; build.
- platform-client: Pest 108 passed (`HostedApplicationTest` 12 new). central-app:
  `LocalProductTest` 5 and `RepositoryCatalogsTest` 4 passed.
- Migrations rolled back (3 steps) and forward on local PostgreSQL (`pharmacy`) and on SQLite.
- Mutations: dropping the sales list's application filter fails the "kept apart" test; dropping
  the `Product` kind scope fails two catalogue tests; delivering outbox rows outside `runAs`
  fails the hosted outbox test.

**Known limitations:** listed under "Status" in `retail-roadmap.md`. The architecture rule
"workspace pages read items, not medicines" passes even though `HomeController` still uses
Pharmacy's `Prescription` (Pest's array-target expectation does not check this case).

## Retail R2 — 2026-10-20 (local, Windows)

**Implemented** (`pharmacy-app`; plan in `retail-roadmap.md`):
- Migration `2026_10_20_000000_add_application_to_stock_documents`: `application` (default
  `pharmacy`) on purchase orders, goods receipts, supplier returns, stock disposals and stock
  transfers.
- Shared actions record the industry being served and refuse other industries' items:
  - `Purchasing` and `ReceiveGoods` refuse them with "This item is not bought here."
  - `Disposals` and `Transfers` refuse them with "This item is not kept here."
  - `ReturnGoods` takes the application of the delivery it returns from.
  - `ReceiveGoods` receives only into the same industry's order.
- `ReceiveGoods` receives a supplier reference (delivery note or invoice) once per supplier,
  ignoring case and spaces. Receipts of one supplier are taken one at a time (supplier row
  locked), so a double submit is refused too. This applies to Pharmacy as well.
- Purchase order, goods receipt, supplier return, disposal, transfer and supplier controllers go
  through `Industry` for pages, permissions, route names and lists. Another industry's documents
  are 404. Suppliers stay shared by both industries.
- `Industry::handles($kind)`; buyable items carry `tracksExpiry`.
- Retail routes and pages:
  - suppliers, purchase orders, goods received (the expiry field shows only for dated products),
    supplier returns, write-offs (disposals) and transfers;
  - a write-off panel on a product's stock page;
  - navigation sections Inventory and Purchasing.
- Catalog `platform/retail.json` 1.1.0 adds `retail.stock.dispose`, `retail.purchasing.view`,
  `.manage` and `.receive`, a write-off separation-of-duties warning, and the new permissions on
  the manager and store keeper roles. Imported into the local platform.

**Tests executed:**
- pharmacy-app: Pest 182 passed, 1 skipped (`RetailPurchasingTest` 10 new). `PurchasingTest`
  now gives each delivery its own invoice number. PHPStan 0; Pint; `vp check`; `tsc`; build.
- central-app: `RepositoryCatalogsTest` 4 passed.
- Migration rolled back and forward on local PostgreSQL (`pharmacy`).
- Mutations, each failing a test:
  - dropping the receipts list's application filter;
  - dropping the transfer's application check;
  - dropping the write-off kind check;
  - dropping the duplicate-delivery check;
  - dropping the order's application check.

**Known limitations:** see "Open after R2" in `retail-roadmap.md`. Receipts without a supplier
reference are not protected against being entered twice.

## Retail R3 — 2026-10-19 (local, Windows)

**Implemented** (`pharmacy-app`; plan in `retail-roadmap.md`):
- Migration `2026_10_21_000000_create_customer_accounts`:
  - `customers` (per organization and application): number, name, contact details, credit limit,
    payment terms in days, active;
  - `customer_id` and `due_on` on sales;
  - `customer_payments` and `customer_payment_allocations`.
- Payment method `account` ("On account"). It is never a way to pay a customer payment or a
  prescription. `PaymentMethod::paidNow()` decides whether money changed hands.
- `CustomerAccounts` (Sales module):
  - What a customer owes is the total of their completed sales on account, less returns and
    payments allocated to them. This is the same sum as Finance's receivable from
    `finance.invoice.issued`, `finance.sales_return.issued` and `finance.payment.received`.
  - Credit check: "Over {name}'s credit limit of …: they owe … and this sale is …."
  - Customer payments (numbered `CP`) settle the oldest due sales first and never more than is
    owed. Each part is sent to Finance as a payment of that sale's invoice, with reference
    `{CP}/{sale}`. Cash needs the receiver's open till, and the till's expected cash includes it.
    A payment is idempotent on its submission id.
  - The customer row is locked while their account changes.
- `Sell` takes an optional customer:
  - on account it needs one, checks the credit limit with the customer locked, and sets the due
    date from the payment terms;
  - the invoice carries `due_on` and `customer_reference` "{name} ({number})";
  - no payment event is sent for a sale on account.
- `VoidSale` refuses a sale on account that the customer has paid towards (it must be returned
  instead). A void or return on account sends no refund.
- `ReturnSale` refuses to take off more than is still owed on a sale on account.
- `Tills::summary` adds the collections from account customers by method.
- `CustomerController` (customers list with balances and overdue flags, add, edit, statement,
  take a payment). Another industry's customers are 404.
- Retail pages:
  - customers list, form, and statement (printable, with a payment form);
  - "On account" at the counter, offered only to members with `retail.sales.credit`; it lists
    the customers with what they can still buy;
  - the customer, due date and amount owed on the sale page;
  - account collections on the till page;
  - Customers in the navigation.
- Catalog `platform/retail.json` 1.2.0 adds:
  - `retail.sales.credit`, `retail.customers.view` and `retail.customers.manage`;
  - a separation-of-duties warning for managing customers together with selling on credit;
  - the permissions on the manager role, and customers view on the cashier role.
  Imported into the local platform (1.2.0 published).

**Tests executed:**
- pharmacy-app: Pest 202 passed, 1 skipped (`RetailCustomersTest` 20 new). PHPStan 0; Pint;
  `vp check`; `tsc`; build.
- central-app: `RepositoryCatalogsTest` 4 passed.
- Migration rolled back and forward on local PostgreSQL (`pharmacy`).
- Mutations, each failing a test:
  - dropping the credit check;
  - dropping the void-after-payment check;
  - dropping the return limit;
  - settling newest sales first;
  - dropping the overpayment check;
  - dropping the customer's application check;
  - leaving account cash out of the till's expected cash;
  - dropping the statement's application check;
  - taking cash without an open till.

**Known limitations:** see "Open after R3" in `retail-roadmap.md`.

## Retail R4 — 2026-10-19 (local, Windows)

**Implemented** (`pharmacy-app`; plan in `retail-roadmap.md`):
- Migration `2026_10_22_000000_create_wholesale_documents`:
  - `price_lists` and `price_list_prices` (per item, by the unit or by a pack);
  - `price_list_id` on customers;
  - `sales_orders` and `sales_order_lines`;
  - `delivery_notes` and `delivery_note_lines`.
- `PriceLists`: a pack the list prices sells at that price; otherwise at the list's unit price
  times the pack's units; otherwise (no list, item not on it, list switched off) at the shop's own
  price.
- `SalesOrders`:
  - a quotation (`QT`) is priced at the customer's list when made, less a discount within the
    member's limit (`retail.sales.discount`, the industry's maximum);
  - confirming it while valid makes it a sales order (`SO`); an expired quotation must be quoted
    again;
  - an order can be cancelled before anything was delivered, and closed short once partly
    delivered.
- `Deliver`:
  - a delivery note (`DN`) takes stock (oldest expiry first) for what is still to deliver on each
    line, never more, and invoices exactly that as a sale on account, due after the customer's
    terms and within their credit limit;
  - Finance gets `finance.invoice.issued` with the due date and the cost per line; no payment;
  - order and customer are locked while delivering; idempotent on the submission id;
  - discounts are shared over deliveries so a whole line invoices its total exactly
    (`SalesOrderLine::amountFor`).
- A delivery's invoice is paid, returned and shown on the statement like any sale on account;
  `VoidSale` refuses it ("take the goods back as a return instead").
- `SalesOrderController` (orders list, quotation form, order page with the delivery form, delivery
  note) and `PriceListController` (lists, prices). Orders are the branch's; other branches' and
  other industries' are 404.
- Retail pages:
  - orders list, quotation form (live prices from the customer's list, stock per line);
  - order page, printable as the quotation, with a delivery form and the deliveries;
  - delivery note, printable with a signature line;
  - price lists and their prices;
  - "Buys at" on the customer form;
  - the delivery on the invoice's sale page;
  - a Wholesale navigation section.
- Catalog `platform/retail.json` 1.3.0 adds:
  - `retail.orders.view`, `.manage` and `.deliver`, and `retail.prices.manage`;
  - a separation-of-duties warning for setting prices together with quoting;
  - the permissions on the manager role, and orders view and deliver on the store keeper role.
  Imported into the local platform (1.3.0 published).

**Tests executed:**
- pharmacy-app: Pest 219 passed, 1 skipped (`RetailWholesaleTest` 17 new). The architecture test
  caught Sales borrowing Procurement's pack trait; the order line has its own helpers now.
  PHPStan 0; Pint; `vp check`; `tsc`; build.
- central-app: `RepositoryCatalogsTest` 4 passed.
- Migration rolled back and forward on local PostgreSQL (`pharmacy`).
- Mutations, each failing a test:
  - delivering beyond what is left;
  - dropping the credit check on delivery;
  - rounding each delivery's discount on its own;
  - delivering on a quotation or closed order;
  - never marking an order delivered;
  - voiding a delivery's invoice;
  - confirming an expired quotation;
  - dropping the discount limit;
  - pricing a pack at the list's unit price;
  - using a list that is switched off;
  - dropping the order's branch check;
  - dropping the price list's industry check.

**Known limitations:** see "Open after R4" in `retail-roadmap.md`.

## Retail R5 — 2026-10-20 (local, Windows)

**Implemented** (`pharmacy-app`; plan in `retail-roadmap.md`):
- The report controllers serve the industry of the request (`Industry`):
  - overview, profitability and purchases filter sales, returns (by their sale), deliveries and
    supplier returns on `application`;
  - the stock value counts only the industry's item kinds;
  - each renders the industry's page (`reports/*` or `retail/reports/*`).
  - The cashier report stays per drawer: tills belong to no industry.
- `SalesAnalysisReportController` (`reports/sales`):
  - sales by category and by cashier, sold less returned, with cost, profit and margin;
  - returns count in the period they were taken, against the cashier who made the sale, so both
    cuts add up to the overview and the profitability report;
  - CSV `categories` and `cashiers`.
- `StockReportController` (`reports/stock`):
  - reorder list: sellable stock at or under the reorder level (the stock page's rule), with the
    shortfall, units sold, pace per day and the last batch cost;
  - slow movers: stock that sold nothing in the period or would last more than 90 days at its
    pace, most money tied up first, with the last sale date;
  - CSV `reorder` and `slow`.
- Routes `retail.reports.*` (`branch.can:retail.reports.view`): overview, sales, margins,
  stock, purchases, tills. CSV `items` is an alias of `medicines` on margins and purchases.
- Pages `retail/reports/{index,sales,profitability,stock,purchases,cashiers}` and a Reports
  item in the shop's menu. `ReportTabs` is the report header for any set of reports;
  `ReportHeader` (pharmacy) and `RetailReportHeader` use it.
- Catalog `platform/retail.json` 1.4.0 adds `retail.reports.view` (sensitivity 2: it shows
  costs and margins), on the manager role.

**Tests executed:**
- pharmacy-app: Pest 231 passed, 1 skipped (`RetailReportsTest` 12 new):
  - access, and keeping each industry out of the other's reports at the same branch;
  - category and cashier cuts adding up to the overview;
  - margins agreeing with the overview;
  - takings equal to the retail Finance postings (invoices less returns);
  - CSV downloads;
  - reorder list, slow movers and the pace over a chosen period;
  - pharmacy returns and deliveries kept out of the shop's reports.
- PHPStan 0; Pint; `vp check`; `tsc`. central-app catalog tests 38 passed; catalog 1.4.0 imported
  locally.
- Mutations, each failing a test: dropping the industry filter on the overview's sales, its
  returns, its stock value, profitability, the sales cuts' returns and purchases; counting returns
  against the returner; ignoring restocked cost; slow movers ignoring cover; the reorder rule. One
  survives as equivalent: the stock report's sales filter (it lists only the industry's items).
- Fixed on the way: the purchases report missed deliveries on the last day of the period on
  SQLite (dates stored with a time); it compares dates now.


## Retail R6–R8, beta — 2026-10-23 (local, Windows)

**Implemented** (`pharmacy-app`, `pharmacy-electron-app`, `packages/platform-client`; plan in
`retail-roadmap.md`):
- R6 gaps:
  - Shelves and holds for the shop: `LocationController` and the batch actions follow the
    request's industry (a batch of the other industry is a 404); retail holds under
    `retail.stock.hold`; pages `retail/locations/*`.
  - Stock-take count sheets in both industries: `StockCount` and `StockCountLine`,
    `Inventory/Actions/StockCounts` (start, record, complete, cancel), `StockCountController`,
    routes `counts.*` and `retail.counts.*`, pages `counts/*` and `retail/counts/*`. Completing
    posts one adjustment per line that differs, once.
  - Editing quotations: `SalesOrders::revise()` relocks a quote, replaces its lines at today's
    prices and keeps its number; `orders.edit` / `orders.update`, "Revise" on the order page.
  - The shared counter, purchasing and stock props are named `items` (were `medicines`).
- R7 later features:
  - Price levels per branch: `branch_counter_settings.price_list_id`, `Sales/Actions/ShelfPrices`;
    the till, the API and quotations price from the branch's list, falling back to the item's
    price. Retail counter settings (`retail.sales.settings`).
  - Promotions: `promotions`, `sale_lines.promotion_id`, `Sales/Actions/Promotions`; the till
    takes the bigger of the running promotion and the member's own discount; page
    `retail/promotions`.
  - Loyalty: `loyalty_programs`, `customers.loyalty_points`, `loyalty_movements`,
    `Sales/Actions/Loyalty`; points earned on a member's sale, redeemed at the till, reversed by
    a void (`ReverseLoyaltyOfVoidedSale`) and by a return; page `retail/loyalty`.
  - Serial numbers: `items.tracks_serials`, `item_serials`, `Sales/Actions/Serials`; a tracked
    product sells one recorded serial per unit, a void releases them
    (`ReleaseSerialsOfVoidedSale`), a return names those back; page `retail/serials`.
- R8 desktop counter:
  - `routes/api.php` registers the counter API for the shop at `{workspace}/retail/api/v1`
    (`retail.api.*`, `platform.app:retail` first), without prescriptions; `AppPages` mirrors the
    shop's pages under `retail/api/v1/app`. The API's items carry `promotion` and
    `tracks_serials`; sales and returns take `serials`; a sale's lines show theirs.
  - platform-client `actingAsAppMember(..., application:)` signs in with a hosted
    application's own mobile client.
  - `pharmacy-electron-app`: Pharmacy or Shop is chosen at sign-in (offered when
    `VITE_RETAIL_CLIENT_ID` is set). Each product has its own path and client; the tokens keep
    the product they were issued for, and a call for the other product gets no token. The
    outbox, held carts, cache and catalog are keyed per product, so a sale queued for the shop
    is never sent to the pharmacy at the same branch (the pharmacy keeps its old keys). The
    shop's menu mirrors `retail-navigation.ts`; the till shows promotions, asks a serial per
    unit of a tracked product, hides expiry, and names the shop in its messages.
- Beta: catalog `platform/retail.json` 1.5.0 (`retail.stock.hold`, `retail.sales.settings`)
  imported locally with the desktop's redirect (`--mobile-redirect=http://127.0.0.1:48217/oauth`),
  which registered the shop's mobile client; the `retail` application set to `beta` locally. It
  is trialable.

**Tests executed:**
- pharmacy-app: Pest 356 passed, 1 skipped. New: `StockCountTest` 7,
  `RetailShelvesAndHoldsTest` 5, `RetailWholesaleTest` +3 (revising quotations),
  `RetailPriceLevelsTest` 5, `RetailPromotionsTest` 5, `RetailLoyaltyTest` 6,
  `RetailSerialsTest` 5, `RetailApiTest` 5. Architecture test green (Inventory does not use Sales).
- packages/platform-client 120 passed. central-app catalog tests 62 passed.
- PHPStan 0 (Api `SaleController`); Pint; `tsc`; electron `typecheck` and build.
- The desktop app driven live (Playwright `_electron`, local platform): signed in to the shop
  with email and password; the shop's menu; a shift opened; Bar soap at 10% off (SSP 270) and a
  phone that would not take payment until its serial was typed; sale S-000004 recorded as
  `application = retail` with the promotion on its line and IMEI-1001 sold. With the main
  process's network cut, two soaps (SSP 540) were kept on the PC, then sent as S-000005 when it
  came back. The pharmacy sign-in checked again after the change.
- Not done: the 2026_10_24 migrations were not rolled back on PostgreSQL (they ran forward).
- The local stack is slow under load (single-threaded `artisan serve` behind Apache): the first
  shop sign-in hit the app's 15-second timeout once and went through on the retry.

## Finance F1 — 2026-10-20 (local, Windows)

Periods and year-end close, the first phase of the standalone Finance & Accounting product
([ADR-0006](../adr/0006-finance-accounting-standalone.md), [roadmap](finance-roadmap.md)).

**Implemented** (`finance-app`):
- Migration `2026_10_20_100000_create_fiscal_years` (additive):
  - `fiscal_years`: name, dates, closed by and when, closing journal;
  - `fiscal_periods`: one row per month, locked by and when.
- `App\Finance\Periods`:
  - adds the first year (from the first of a month) or the next one (always right after the
    last), 1–24 months, split into months;
  - locks months from the earliest and unlocks them from the latest, so the books are always
    "locked up to" one date;
  - `postingDate()`, asked by `Ledger` for every journal:
    - `ENFORCE` (anything entered in Finance) refuses a locked day or a day before the books
      start;
    - `MOVE` (every product event, through `PostEvent::source`) posts on the first open day
      with "(dated …, books locked)" in the memo;
    - `CLOSING` is used only by the year-end close.
  - Before any year exists nothing is locked, exactly as before.
- `App\Finance\YearEnd`:
  - close: years in order; locks every month and posts one `year_close` journal on the last day,
    each revenue and expense account against retained earnings (no journal when there was no
    result); the journal may clear accounts closed during the year;
  - reopen: latest closed year only; reverses the closing journal (`year_reopen`), and the months
    stay locked until unlocked.
- Profit and loss leaves closing journals out (`Balances::totals(withClosing: false)`); the
  trial balance and balance sheet include them.
- `App\Finance\OpeningBalances`: one `opening_balances` journal on the first day of the books,
  for asset, liability and equity accounts; the difference goes to the new system account 3900
  "Opening balance equity" (created by `ChartOfAccounts::ensure`); saving again reverses the
  previous journal, refused once the first month is locked.
- Pages `periods` (years, months, lock and unlock, close and reopen with a confirmation) and
  `opening-balances` (debit and credit per account, running difference). Menu: Accounting →
  Periods & close.
- Catalog 1.1.0: `finance.periods.manage` (sensitivity 3), on the Accountant role. Viewing
  periods needs `finance.journals.view`. Imported locally with `platform:local-product`.

**Tests executed:**
- finance-app: Pest 57 passed, 1 skipped (`PeriodsTest` 8 new):
  - years and months;
  - locking and unlocking in order, manual entries refused on locked days and before the books
    start;
  - nothing limited before a year exists;
  - a product event in a locked month moved to the first open day;
  - close and reopen, with P&L unchanged, retained earnings, the balance sheet balancing and the
    post-closing trial balance;
  - a close without a result;
  - opening balances posted, replaced and refused (revenue account, locked month);
  - permissions.
- PHPStan 0; Pint; `vp check`; `tsc`; build.
- Local PostgreSQL: `migrate`, `migrate:rollback --step=1`, `migrate`.

**Known limitations:**
- Opening balances post receivable and payable as totals, with no open invoices or bills behind
  them, so F2's aged reports will not show them.
- Months are calendar months only.
- A closed year's documents (bills, invoices) can still be paid or voided in an open month. That
  is correct; it just means no document is ever "frozen".
- Not yet checked in a browser.

## Finance F2 — 2026-10-20 (local, Windows)

Reports for the standalone Finance & Accounting product ([roadmap](finance-roadmap.md)).

**Implemented** (`finance-app`):
- Migration `2026_10_20_120000_add_cash_flow_to_accounts` (additive): `accounts.cash_flow`
  (operating, investing, financing; null = automatic).
- `Account::cashFlowActivity()`:
  - banks and cash, revenue and expenses: none;
  - otherwise the accountant's choice;
  - otherwise receivable, inventory, payable and tax are operating, other assets investing, other
    liabilities and equity financing.
  - Chosen on the chart of accounts ("In the cash flow under").
- `App\Finance\Reports`:
  - `generalLedger`: per account, the opening balance, each line with its running balance and the
    closing balance; one account or all; at most 5,000 lines listed (totals always complete,
    with a notice when cut);
  - `agedReceivables` / `agedPayables`: open invoices and bills today, in buckets (not yet due,
    1–30, 31–60, 61–90, over 90 days past due; the document date when there is no due date), by
    customer or supplier and per document, against the receivable or payable account; the
    difference (opening balances, journals, unapplied payments or credits) is shown;
  - `cashFlow` (indirect): profit, then every non-bank balance sheet account's movement as cash
    in or out by activity, without year-end closing journals; ends at the change in bank and
    cash, shown against the opening and closing balances.
- `Statements::compared`: P&L with the previous period (same number of whole months, else days)
  or the same period last year; balance sheet with the end of the previous month or the same day
  last year. Accounts in either period are listed.
- CSV (`?export=`) on every report: trial balance `accounts`, P&L, balance sheet and cash flow
  `statement`, general ledger `lines`, aged `parties` and `documents`. `App\Support\CsvExport`
  (same as Pharmacy's, amounts in the books' currency).
- Pages `reports/{cash-flow,general-ledger,aged}`; statements gain a "Compare with" choice and a
  second column; Print and CSV on every report (filters hidden when printing). Menu: Reports →
  Cash flow, General ledger, Aged receivables, Aged payables.

**Tests executed:**
- finance-app: Pest 65 passed, 1 skipped (`ReportsTest` 8 new):
  - general ledger balances, the account filter and CSV;
  - receivables ageing (buckets, by customer, matching the account, the difference) and CSV;
  - payables ageing from a bill event;
  - cash flow by activity ending at the bank change, a reclassified account, and a year-end close
    left out;
  - P&L compared with the previous period and last year, and its CSV;
  - balance sheet compared with the previous month end;
  - trial balance CSV and permissions.
- PHPStan 0; Pint; `vp check`; `tsc`; build.
- Local PostgreSQL: `migrate`, `migrate:rollback --step=1`, `migrate`.

**Known limitations:**
- Ageing is as of today only. Past dates would need payments and returns dated per document;
  credits on invoices (`credited_minor`) carry no date.
- The general ledger CSV holds the same 5,000 lines as the page.
- Not yet checked in a browser.

## Finance F3 — 2026-10-20 (local, Windows)

Bank reconciliation for the standalone Finance & Accounting product ([roadmap](finance-roadmap.md)).

**Implemented** (`finance-app`):
- Migration `2026_10_21_100000_create_bank_reconciliation` (additive):
  - `accounts.bank_import` (the bank's CSV layout, remembered per account);
  - `bank_statements` (account, period, opening and closing balance, reconciled by and when);
  - `bank_statement_lines` (date, description, reference, signed amount, the one
    `journal_line_id` it clears, unique);
  - `bank_rules` (description contains, direction, bank account or all, account, memo, order).
- `App\Finance\Reconciliation`:
  - import: a statement opens with the previous one's closing balance (the first one's is
    typed); it must end after the previous one; lines on or before the previous end are skipped
    as already imported, and lines after its own date refused;
  - candidates: ledger lines on the bank account for the same signed amount, not cleared, within
    30 days, closest first;
  - match and unmatch, while the statement is open;
  - enter: a line recorded as money in or out (`Banking`) against another account, then matched;
  - auto-match: exactly one candidate within 3 days is matched; a line with no candidate within
    30 days is entered by the first rule that covers it;
  - reconcile (every line matched, opening + lines = closing, earlier statements reconciled),
    undo (latest first), remove (latest, unreconciled);
  - report on the statement's last day: balance per bank + deposits not yet on a statement −
    payments not yet on one − lines cleared against later entries = expected books balance,
    against the books, with any unexplained difference.
- `Ledger::reverse` refuses a journal with a line matched to a statement line ("unmatch it there
  first").
- Pages:
  - `reconciliation`: statements, and the import (the CSV is read in the browser; choose the
    columns, date format, decimal mark, one signed amount column or money in/out columns;
    preview and unreadable lines; statement date, number, balances);
  - `reconciliation/{statement}`: lines with suggestions, Match, record a line (rule
    pre-filled), Match automatically, Reconcile, Undo, Remove, and the reconciliation report
    with the items not yet on a statement;
  - `bank-rules`.
  - Menu: Banking → Reconciliation.
- Catalog 1.2.0: `finance.banking.reconcile` (sensitivity 3) on Accountant and Bookkeeper;
  imported locally.

**Tests executed:**
- finance-app: Pest 72 passed, 1 skipped (`ReconciliationTest` 7 new):
  - import order, carried balance, skipped lines and saved layout;
  - suggestions, matching once, a wrong-way amount refused, reversal blocked while matched;
  - recording a line;
  - auto-match and rules;
  - reconcile conditions, removal order, locking and undo;
  - the bank-to-books report;
  - permissions.
- Vitest: `csv.test.ts` (4: quoted CSV, separators, date orders, amount forms).
- PHPStan 0; Pint; `vp check`; `tsc`; build.
- Local PostgreSQL: `migrate`, `migrate:rollback --step=1`, `migrate`.
- Mutation: removing the reversal guard fails the matching test.

**Known limitations:**
- One statement line clears one ledger line. A deposit of several receipts, or one receipt paid
  in two bank lines, needs a journal to combine them first.
- No direct bank feeds (roadmap "Later"); statements in OFX or PDF are not read.
- Suggestions are shown for the first 200 unmatched lines of a statement.
- Not yet checked in a browser.

## Finance F4 — 2026-10-20 (local, Windows)

Tax codes and tax returns for the standalone Finance & Accounting product ([roadmap](finance-roadmap.md)).

**Implemented** (`finance-app`):
- Migration `2026_10_21_120000_create_tax` (additive):
  - `tax_codes` (code, name, rate in basis points, on sales, on purchases, recoverable, active;
    unique per organization);
  - `journal_lines.tax_kind` (output or input), `tax_code_id`, `tax_base_minor` (the net amount
    taxed), set when the line is posted;
  - `tax_returns` (period, reference, output, input, adjustments, net as filed, filer, settlement
    journal; unique start per organization).
- The tax account stays the one system account 2200. `Ledger` writes the tax fields; a reversal
  copies them with the base negated, so a void lowers output tax.
- Product events tag their tax lines: output on invoices, voids and sales returns; input on
  bills and supplier credits (no code: products send one tax amount).
- Invoices and bills raised in Finance take a tax code per line:
  - tax per line, rounded half up; one tax line per code, with its base;
  - purchase tax that is not recoverable is added to the line's cost;
  - the free "tax" amount is still accepted (and shown only while there are no codes) as tax
    without a code.
- `App\Finance\Tax`:
  - report for a period: tax account lines grouped by kind and code, settlements and opening
    balances left out; net = output − input + adjustments = the tax account's movement;
  - file: returns follow one another with no gap, end before today, and store the totals;
  - settle: Dr tax / Cr bank for what is owed, the reverse for a refund (`tax_settlement`);
  - unfile: latest only; its settlement is reversed today.
- `Periods::postingDate` locks journals that touch the tax account up to the latest filed return
  ("Tax is filed up to …"); product events are moved past it like a locked month.
- Pages: `tax-codes` (add, edit; the rate is fixed once added), `tax-returns` (next period as it
  stands, file), `tax-returns/{return}` (figures, CSV, settle, unfile, a warning if the ledger
  no longer matches the filed figures). Tax column in the invoice and bill line editor. Menu:
  Tax → Tax returns, Tax codes.
- Catalog 1.3.0: `finance.tax.manage` (sensitivity 3) on Accountant; imported locally.

**Known limitations:**
- Zero-rated sales have no tax line, so their net amount is not on the return.
- Tax from products has no code; it is reported per kind under "Without a code".
- One tax account: no separate input and output tax accounts.

**Tests executed:**
- finance-app: Pest 79 passed, 1 skipped (`TaxTest` 7 new):
  - codes unique (case-insensitive), rate fixed;
  - invoice tax per line and code, rounding, tagged tax line;
  - recoverable and non-recoverable purchase tax;
  - the report from events, a void, a bill and a manual adjustment equals the tax account;
  - the filed-period lock for manual entries and events, sequential returns;
  - settlement, later returns without it, unfiling in order;
  - CSV and permissions.
- `TaxTest`, `PeriodsTest` and `ReconciliationTest` also run on a throwaway PostgreSQL database
  (22 passed), then dropped.
- PHPStan 0; Pint; `vp check`; `tsc`; Vitest 4; build.
- Local PostgreSQL: `migrate`, `migrate:rollback --step=1`, `migrate`.
- Mutation: turning off the filed-return lock fails 2 tests.

## Finance F5 — 2026-10-20 (local, Windows)

Cost centres and projects for the standalone Finance & Accounting product ([roadmap](finance-roadmap.md)).

**Implemented** (`finance-app`):
- Migration `2026_10_22_100000_create_dimensions` (additive):
  - `dimension_values` (kind `cost_centre` or `project`, code, name, active; code unique per
    organization and kind);
  - `journal_lines.cost_centre_id`, `project_id`, set when the line is posted;
  - `accounts.requires_cost_centre`, `requires_project`.
- `Ledger`: lines may name a cost centre and a project (of the right kind); a reversal keeps
  them. Entries made by hand (`require_dimensions`: manual journals, invoices, bills) must name
  what their accounts require ("6000 Rent needs a cost centre."); products' events and
  automatic entries are never refused for it.
- `Balances::totals` filters by cost centre and/or project (a value, or `none`);
  `Balances::totalsBy` splits by one of them.
- `Statements::profitAndLossBy`: a column per value with income or spending in the period, one
  for lines without any, and the total (the plain P&L).
- Pages:
  - `dimensions` (Accounting → Cost centres & projects): add, rename, close; codes upper case;
  - cost centre and project selects under each line's account on journals, invoices and bills,
    marked required where the account says so;
  - account form: "Lines entered by hand need a cost centre / a project";
  - journal detail shows each line's cost centre and project;
  - P&L and trial balance: cost centre and project filters (a filtered trial balance is marked
    as such instead of balanced/out of balance), kept in CSV and print;
  - `reports/profit-and-loss-by-dimension` (Reports → P&L by dimension), each column header
    opening the P&L filtered to it; CSV.
- Permissions: viewing needs `finance.accounts.view`, managing `finance.accounts.manage`; no
  catalog change.

**Known limitations:**
- Branch is not yet a filter (products send branch ids; Finance has no branch names).
- Money in/out, reconciliation entries and bank rules do not ask for a cost centre or project.
- One cost centre and one project per line; no splitting a line across several.

**Tests executed:**
- finance-app: Pest 84 passed, 1 skipped (`DimensionsTest` 5 new):
  - unique codes per kind, closing, closed values refused and not offered;
  - required dimensions on journals and bills, wrong kind refused, reversal keeps them, product
    events never refused;
  - P&L by cost centre: columns, "none", totals equal the P&L; filtered P&L and trial balance;
    unknown and malformed filters refused; CSV;
  - permissions.
- `DimensionsTest`, `ReportsTest`, `TaxTest`, `BookkeepingTest` and `PeriodsTest` also on a
  throwaway PostgreSQL database (53 passed), then dropped. It caught "none" and non-UUID filter
  values reaching uuid columns (SQLite accepts them); fixed.
- PHPStan 0; Pint; `vp check`; `tsc`; Vitest 4; build.
- Local PostgreSQL: `migrate`, `migrate:rollback --step=1`, `migrate`.
- Mutation: turning off the required-dimension check fails 2 tests.

## Finance F6 — 2026-10-20 (local, Windows)

Other currencies for the standalone Finance & Accounting product ([roadmap](finance-roadmap.md)).
Plan feature `finance.multicurrency`: without it the pages are not found and documents stay in
the books' currency; what was entered in other currencies stays.

**Implemented** (`finance-app`, catalog 1.4.0):
- Migration `2026_10_23_100000_create_multicurrency` (additive):
  - `exchange_rates` (currency, day, rate to 10 decimals; one per organization, currency and day);
  - `accounts.currency` (bank and cash accounts only);
  - `journal_lines.currency`, `currency_minor` (signed, + on the debit side);
  - invoices and bills: `currency`, `exchange_rate`, `currency_total_minor`, `currency_paid_minor`;
  - customer and supplier payments: `currency`, `exchange_rate`, `currency_amount_minor`,
    `fx_minor`; `bill_payments.currency_amount_minor`;
  - `revaluations` (day, journal, its reversal).
- New system account 6950 "Exchange gains and losses" (added to existing charts on first use).
- `Currencies`: the latest rate on or before a day; conversion with bcmath, half away from zero.
- `Ledger`: lines may carry an amount in another currency. An account held in another currency
  takes only lines in that currency, so it is posted to from Banking and documents, not by hand.
- `Banking`: money in/out of a foreign account at the day's (or a typed) rate; transfers between
  currencies take the amount that arrived, the difference against the day's rate going to 6950.
- `Receivables` / `Payables`: invoices and bills in another currency, each line converted at the
  document's rate. Payments are in the document's currency, into or from an account in that
  currency or the books', at the day's rate. The receivable or payable cleared is the document's
  rate (the full remainder on the last payment). The realised difference posts to 6950.
- `Revaluations`: at a day's rates, foreign bank balances and open foreign invoices and bills are
  brought to their value that day against 6950. The journal is dated that day and reversed the
  next. Each day can be revalued once. Days that have no rate are refused with the missing
  currency named.
- Pages:
  - `currencies` (Accounting → Currencies, shown with the feature): rates (add, replace, remove),
    accounts in other currencies, a revaluation preview for any day, revalue, and past
    revaluations;
  - a currency field on bank accounts (chart of accounts and Banking);
  - a currency and rate on new invoices and bills, with the lines entered in that currency;
  - invoice and bill pages show the foreign total, outstanding and payments. Receiving and paying
    take a rate; supplier payments go per currency;
  - Banking shows foreign balances and movements, with a rate field and an "arrived" amount on
    cross-currency transfers.
- Permission `finance.currencies.manage` (Accountant) for rates and revaluation. Viewing needs
  `finance.reports.view`.
- `platform-client`: `PlatformFake::grant` and `actingAsMember` take plan features.

**Known limitations:**
- Bank reconciliation and opening balances are for accounts in the books' currency only.
- Products' events stay in the books' currency.
- A payment in another currency is set in full against bills; nothing is left on account.
- Aged reports are in the books' currency. On a revaluation day they differ from the receivable
  and payable accounts by the revaluation.
- A revaluation cannot be undone. Its reversal follows the next day.
- Central must grant the feature (`finance.multicurrency` entitlement).

**Tests executed:**
- finance-app: Pest 90 passed, 1 skipped (`MulticurrencyTest` 6 new):
  - the feature gate: pages are not found and documents and accounts stay in the books' currency;
  - rates: one per day (replaced), the books' currency and zero refused, the latest offered;
  - a dollar invoice paid in two parts, at a gain into a shilling account and at a loss into a
    dollar account, cleared exactly; an account in a third currency refused;
  - a dollar bill paid from a dollar account bought by transfer, with the loss; a transfer out
    at a gain; manual journals to the dollar account refused; the books balance throughout;
  - revaluation of a bank, an invoice and a bill: the preview, the posting, the reversal the
    next day, one per day, no future days;
  - permissions.
- `MulticurrencyTest`, `BookkeepingTest` and `TaxTest` also run on a throwaway PostgreSQL
  database (38 passed); the database was then dropped.
- PHPStan 0; Pint; `vp check`; `tsc`; Vitest 4; build.
- Local PostgreSQL: `migrate`, `migrate:rollback --step=1`, `migrate`.
- Mutation: flipping the side of a receipt's exchange difference fails a test. Counting a bill's
  revaluation as a gain also fails a test (it survived until an open bill was added to the test).

## Finance F7 — 2026-10-20 (local, Windows)

Budgets for the standalone Finance & Accounting product ([roadmap](finance-roadmap.md)).

**Implemented** (`finance-app`, catalog 1.5.0):
- Migration `2026_10_24_100000_create_budgets` (additive):
  - `budgets`: financial year, name (unique per year), optional cost centre;
  - `budget_lines`: account, month of the year (period number) and amount, in the account's
    normal direction.
- `Budgets`:
  - create a budget empty, or from the previous year's profit and loss month by month,
    changed by a percentage (half away from zero);
  - save replaces the grid and drops zero amounts;
  - `report` compares the budget with actuals for a range of months. The actual column is
    `Statements::profitAndLoss` for those months, filtered to the budget's cost centre if it
    has one. The variance is actual minus budget. Over budget counts as favourable for revenue
    and unfavourable for expenses.
- Pages:
  - `budgets` (Accounting → Budgets): the list with revenue and expense totals, and a new
    budget form (year, name, organization or cost centre, start from nothing or from last year
    changed by a %);
  - `budgets/{id}`: a grid of revenue and expense accounts by month, with row, column and
    planned-profit totals and "spread a year" per account; rename, save, delete;
  - `reports/budget-vs-actual` (Reports → Budget vs actual): pick a budget and a range of
    months; budget, actual (linked to the matching P&L), and variance with a % coloured by
    whether it helps the result; CSV and print.
- Permission `finance.budgets.manage` (Accountant). Viewing needs `finance.reports.view`.

**Known limitations:**
- Budgets cover revenue and expense accounts only, not balance sheet or cash budgets.
- A budget is for the whole organization or one cost centre. There are no project budgets and
  no split by cost centre inside one budget.
- Copying matches months by number, so a previous year of a different length copies only the
  months both years have.
- There are no versions or approval of a budget; saving overwrites it.

**Tests executed:**
- finance-app: Pest 94 passed, 1 skipped (`BudgetsTest` 4 new):
  - copy from last year with +10% and rounding; duplicate names refused; no earlier year
    refused;
  - the grid: blanks and zeros dropped, negatives kept, replaced on save, accounts other than
    revenue or expense refused, too many decimals refused;
  - budget vs actual over January–February: budget, actual, variance and favourability; the
    actual totals equal `Statements::profitAndLoss`; a cost centre budget sees only its
    lines; months in the wrong order refused; CSV;
  - permissions, and deleting a budget removes its lines.
- `BudgetsTest`, `PeriodsTest` and `ReportsTest` also run on a throwaway PostgreSQL database
  (20 passed); the database was then dropped.
- PHPStan 0; Pint; `vp check`; `tsc`; Vitest 4; build.
- Local PostgreSQL: `migrate`, `migrate:rollback --step=1`, `migrate`.
- Mutation: dropping the cost centre filter from the actuals fails a test. Treating overspending
  as favourable also fails a test.

## Finance F8 — 2026-10-20 (local, Windows)

Fixed assets for the standalone Finance & Accounting product ([roadmap](finance-roadmap.md)).
Plan feature `finance.fixed_assets`: without it the pages are not found.

**Implemented** (`finance-app`, catalog 1.6.0):
- Migration `2026_10_25_100000_create_fixed_assets` (additive):
  - `asset_categories`: asset, accumulated depreciation and expense accounts; method; life;
    yearly rate for declining balance;
  - `fixed_assets`: number FA-0001…, cost, residual value, depreciation brought in, method, life
    and rate (copied from the category, adjustable), the acquiring journal and bill, and the
    disposal;
  - `depreciation_runs`: one per organization and month; `depreciation_lines`: one per asset
    and run.
- Default accounts 1590 Accumulated depreciation, 6400 Depreciation, and 6960 Gain or loss on
  disposal of assets (a system account, added to existing charts on first use).
- `FixedAssets`:
  - `register` posts nothing, because the cost is already in the asset account. Against a bill
    or journal, the asset accounts must have been debited at least the cost, less what other
    assets already took from that entry. An asset already in the books can bring depreciation
    so far with it.
  - Schedule: straight line, with rounding spread so the months add up exactly; or declining
    balance at the yearly rate ÷ 12, with the last month of life taking the asset to its
    residual value. Depreciation starts in the month of acquisition. Never below the residual
    value.
  - `run`: months that have started, in order, each once (refused, plus a unique index). Each
    run charges what brings every held asset to its schedule, so an asset registered late
    catches up. One journal at the month end, expense against accumulated depreciation, per
    category.
  - `undo`: the latest run only, reversed at the same date. Refused when an asset it charged
    has since been disposed of.
  - `dispose`: removes the cost and depreciation from the books, puts the proceeds in a bank or
    cash account, and posts the gain or loss to 6960. Refused when months after the disposal
    are already depreciated.
- Pages:
  - Fixed assets → Asset register: assets with cost, depreciation and value, held totals, and
    for each asset and accumulated depreciation account the ledger against the register
    ("Matches" / "Differs"); register from a bill, a journal, or assets already in the books;
  - the asset page: summary, the schedule month by month (run, or still to come), and
    disposal;
  - Fixed assets → Depreciation: the next month's charges per asset, run, past runs, and undo
    the latest;
  - Fixed assets → Asset categories: add and edit.
- Permission `finance.assets.manage` (Accountant). Viewing needs `finance.accounts.view`.
- `RequireFeature:<key>` middleware replaces `RequireMulticurrency`; navigation items name
  their plan feature.

**Known limitations:**
- No depreciation is charged in the month of disposal unless that month was run before.
- Changing a category does not change assets already registered. An asset's terms are fixed
  once it is registered.
- No revaluation or impairment of assets, and no partial disposals.
- The register reconciles only if the asset and accumulated depreciation accounts are posted
  through the register (or its sources).

**Tests executed:**
- finance-app: Pest 100 passed, 1 skipped (`FixedAssetsTest` 6 new):
  - the plan feature gate;
  - registering from a bill and from a journal; more than the entry put in refused; residual
    value refused;
  - runs in order and once; future months refused; straight line and declining balance
    (40%: 3,333 then 3,222, both charged in March after late registration); an asset stops at
    its residual value; the accumulated and expense accounts equal the runs;
  - undo only the latest run, and a rerun gives the same;
  - disposal at a gain into the bank and a write-off at a loss; disposal before a run month
    refused; a disposed asset is not depreciated afterwards and its run cannot be undone;
  - permissions.
  - Each scenario checks that the register matches the ledger for the asset and accumulated
    depreciation accounts.
- `FixedAssetsTest`, `MulticurrencyTest`, `BookkeepingTest` and `BudgetsTest` also run on a
  throwaway PostgreSQL database (41 passed); the database was then dropped. This run caught
  `FOR UPDATE` with `SUM` when registering against a journal (SQLite accepts it). Fixed by
  locking the journal row.
- PHPStan 0; Pint; `vp check`; `tsc`; Vitest 4; build.
- Local PostgreSQL: `migrate`, `migrate:rollback --step=1`, `migrate`.
- Mutation: dropping the end-of-life cap (depreciating below the residual value) fails a test.
  Allowing disposal before a depreciated month also fails a test.

## Counter: cash in and out, offline sales on the app — 2026-10-23 (local, Windows)

Cash put into or taken out of an open till, and sales the mobile counter makes without a
connection.

- Migration `2026_10_23_000003_create_till_cash_movements` (additive): `till_cash_movements`
  (direction `in`/`out`, counterpart `safe`/`bank`/`expense`, amount, reason, who, submission id
  unique per organization).
- `Sales\Actions\Tills::move`: open tills only; an amount above zero and a reason; no more out
  than the drawer should hold; `expense` only out; the same submission id answers the earlier
  movement. The summary adds cash in and out, and expected cash = float + cash taken + cash
  collected − cash refunds + in − out.
- Finance: to or from the safe stays within cash and is not sent. Banked, from the bank, or paid
  out sends `finance.cash.moved` v1 (new contract): Dr bank / Cr cash, the reverse, or Dr the
  expense / Cr cash. The expense is the active expense account the event names, otherwise Other
  expenses (new system key `other_expenses` on 6900; charts made before the key are found by
  code).
- Pages: `tills/{till}` (pharmacy and retail) list the movements and record one
  (`POST tills/{till}/movements`): the cashier on their own till, or a manager in the branch.
  The cashier report's till CSV has Cash in and Cash out columns.
- API: `POST api/v1/till/movements` (`counterpart` defaults to the safe); the till includes
  `movements`, `summary.cash_in_minor` and `summary.cash_out_minor`, shown even when counted blind.
- Mobile: Shift tab, Cash in / Cash out (safe, bank, expense). Offline sales: a sale that finds no
  connection can be queued on the device with its submission id and is sent when the connection
  is back, by the same person at the same branch only. A refused one waits with the reason (back
  to the cart, try again, discard). The shift does not close while sales wait. Dispensing is
  never queued.
- `PlatformFake::publishKey()`: the fake JWKS (which now also signs the mobile app's tokens)
  publishes another test key. Finance's tests use it for their event key; their own
  `Http::fake` of the JWKS was no longer reached.

**Known limitations:**
- Cash paid out goes to Other expenses: the till cannot name an expense account yet.
- A queued sale is recorded when it arrives: its time and its shift are those of sending.
- No receipt for a queued sale before it is sent.

**Tests executed:**
- Pharmacy: Pest 309 passed, 1 skipped (new: 2 `TillTest`, 1 `MobileApiTest`, 1
  `FinanceAdjustmentsTest`); PHPStan 0; Pint; `tsc`.
- finance-app: `EventsTest` 19 passed (2 new). The full suite has one failure that predates this
  work: `TaxTest` › "locks a filed period" uses the real date and expects 2026-10-20 to be
  in the future.
- platform-client: Pest 120 passed; PHPStan 0; Pint.
- Mobile: `tsc`, ESLint, `expo-doctor` 20/20, Android and web bundles. Not exercised on a device.

## HR Phase H3 — Leave — 2026-10-23 (local, Windows)

**Implemented** (`hr-app`, module `App\Modules\Leave`, catalog 1.1.0):
- Kinds of leave per organization (`leave_types`): days per year, paid, "no limit" (the balance
  may go below zero, e.g. unpaid leave), most days carried into the next year; archived, never
  deleted. A new workspace starts with Annual (21, carry 5), Sick (14) and Unpaid (no limit),
  only if it has none: starting values, not a statutory preset.
- Entitlements per person, kind and year (`leave_entitlements`), made the first time a year is
  used: the kind's days plus unused days of the year before, up to its carry-over limit. HR
  changes one person's days for a year (part year, long service). Balances are never stored:
  days + carried − approved; waiting requests hold days ("available").
- Public holidays per organization and date (`public_holidays`). Days are working days: Monday to
  Friday, less public holidays, counted once when requested.
- Requests (`leave_requests`): pending → approved | rejected | cancelled; approved → cancelled
  before it starts. Within one calendar year; no overlap with waiting or approved leave; refused
  for someone who has left or before their start date. The balance is checked when requested
  and again, under a lock, when approved. Someone leaving has waiting requests and leave after
  their last day cancelled.
- Separation of duties: whoever recorded a request cannot decide on it, nor can the person it is
  for (their sign-in address is their work email; H7 replaces this with the membership link).
- Permissions: `hr.leave.view` / `.manage` / `.approve` (organization, branch, department) and
  `hr.leave.settings` (organization). Line manager: view, approve (role sensitivity 1 → 2); HR
  officer: view, manage, approve; HR manager: all four.
- Pages: Leave (away today, requests by status, record, decide with a note, cancel), each
  person's leave by year (balances, change days, requests; linked from their profile), Leave
  settings (kinds, holidays by year). Home shows who is away today and what waits.

**Known limitations:**
- Whole days only; no half days. The working week is Monday to Friday for every organization.
- Carry-over is fixed when the new year's entitlement is made; later changes to the old year do
  not move it.
- No self-service yet (H7): HR records requests for staff. No notifications to approvers.
- Approved leave that has started cannot be shortened (early return).

**Tests executed:**
- hr-app: Pest 40 passed (11 new in `LeaveTest`, 1 in `ProvisioningTest`); PHPStan 0; Pint;
  `vp check`; `tsc`; `vp build`.
- Migration forward, back and forward again on PostgreSQL (local `hr` database).
