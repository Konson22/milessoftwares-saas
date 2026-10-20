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

