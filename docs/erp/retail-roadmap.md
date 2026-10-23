# Retail & Trading roadmap

Retail is an industry module of the ERP workspace application (`pharmacy-app`, renamed later), served
at `{workspace}/retail` from the same deployment and database as Pharmacy
([ADR-0003](../adr/0003-erp-workspace-application.md)). It is its own registry application `retail`
(own catalog, clients, entitlement and roles), so it is sold and permitted separately, and it reuses
the shared Inventory, Procurement and Sales modules and the Finance event feed: there is no second
stock, purchasing or sales engine.

Every phase: tests, Pint, PHPStan, `tsc` and build green; migrations rolled back and forward on
PostgreSQL; results in `implementation-status.md`. The application and the `retail_trading` product
stay `planned` (shown as coming soon, not sold) until the pilot list below is met.

| Phase | Scope | Acceptance |
| --- | --- | --- |
| R0 Hosting | platform-client serves several registry applications from one deployment (`hosted`): per-application path, clients, webhook secret, routes, cache keys and outbox source; local registration of a hosted application | a member allowed in Pharmacy but not Retail gets 403 on `/retail`; the organization lookup and access answers of one application are never served to the other; events from a retail sale carry `source_app: retail` and use retail's clients |
| R1 Catalogue and counter | `retail` catalog 1.0.0 (roles, sensitivity); products on the shared item master (kind `product`: barcode, category, unit price, packs, reorder level, optional expiry tracking); opening stock without expiry; barcode counter on the shared `Sell` action and tills (cash, mobile money, card), receipts, voids and returns; retail home | a retail product is never sold as a medicine or vice versa; a scanned barcode adds the product; a sale, its stock and its Finance events commit together; restricted items never reach the retail counter |
| R2 Purchasing and stock | retail screens over the shared purchase orders, receipts, supplier returns, counts, write-offs, locations and transfers | the same delivery cannot be received twice; stock pages show only retail products |
| R3 Customers and credit | customer accounts (Sales), credit limit, sales on account (`finance.invoice.issued` with a due date), customer payments and statements | a sale on account over the limit is refused; statement balance = Finance receivable |
| R4 Wholesale documents | price lists (retail / wholesale), quotations → sales orders → delivery notes → invoices, partial deliveries | an order line is never delivered beyond its quantity; an invoice equals what was delivered |
| R5 Reports | sales by product, category, cashier and branch; margins; slow movers; reorder list; CSV export | totals equal the sales and Finance postings for the period |
| R6 Gaps | shelves and holds for the shop, stock-take count sheets (both industries), editing quotations, shared props renamed `medicines` → `items` | a count posts only the differences, once; a revised quotation keeps its number and is priced afresh; one industry's batches are never held or counted from the other |
| R7 Later features | price levels per branch, promotions, loyalty points, serial numbers | the till takes the bigger of the promotion and the member's discount; points earned and redeemed are reversed by a void and a return; each tracked unit is sold once and comes back by its serial |
| R8 Desktop counter | the counter API at `{workspace}/retail/api/v1` (own mobile client) and the shop's pages for the app; `pharmacy-electron-app` signs in to the pharmacy or the shop and sells offline for either | a pharmacy token never calls the shop's API; a sale queued for the shop is never sent to the pharmacy at the same branch |
| Later | SUNMI handheld POS (Phase 11), native desktop screens for the back office | — |

## Permissions (catalog 1.5.0)

| Permission | Sensitivity | Scopes |
| --- | --- | --- |
| `retail.products.view` | 1 | organization, branch |
| `retail.products.manage` | 2 | organization |
| `retail.stock.view` | 1 | organization, branch |
| `retail.stock.adjust` | 3 | organization, branch |
| `retail.stock.hold` | 3 | organization, branch |
| `retail.sales.view` | 1 | organization, branch |
| `retail.sales.create` | 2 | organization, branch |
| `retail.sales.discount` | 2 | organization, branch |
| `retail.sales.void` | 3 | organization, branch |
| `retail.tills.manage` | 3 | organization, branch |
| `retail.sales.settings` | 3 | organization, branch |
| `retail.stock.dispose` | 3 | organization, branch |
| `retail.purchasing.view` | 1 | organization, branch |
| `retail.purchasing.manage` | 2 | organization, branch |
| `retail.purchasing.receive` | 2 | organization, branch |
| `retail.sales.credit` | 3 | organization, branch |
| `retail.customers.view` | 2 | organization, branch |
| `retail.customers.manage` | 3 | organization |
| `retail.orders.view` | 1 | organization, branch |
| `retail.orders.manage` | 2 | organization, branch |
| `retail.orders.deliver` | 3 | organization, branch |
| `retail.prices.manage` | 3 | organization |
| `retail.reports.view` | 2 | organization, branch |

Roles: Cashier (sell, own till), Store keeper (products view, stock, purchasing view and receive),
Store manager (everything at the branch, discounts, voids, tills, purchase orders, write-off
approvals, selling on account, customers, orders, price lists, reports), Owner (everything). Cashiers can
look customers up; store keepers see orders and deliver them.
Catalog 1.1.0 (R2) added write-off approval and purchasing; 1.2.0 (R3) added credit and
customers; 1.3.0 (R4) added orders and price lists; 1.4.0 (R5) added reports; 1.5.0 (R6–R7)
added holds and counter settings, and `retail.prices.manage` now also covers branch price levels,
promotions and loyalty.

## Pilot list (`planned` → `beta`)

R0–R2 done; a real shop's catalogue loaded (barcodes scanned at the counter on the shop's own
scanner); one week of sales reconciled against Finance; backups restored once.

Beta was opened on 2026-10-23 at the owner's call, before the last three items: the catalogue,
the week of reconciled sales and the backup restore are still to do with the first shop. Locally
the application is `beta` and open for trials. In production its status is set in Admin →
Applications (the catalog import does not change it) once catalog 1.5.0 is imported there.

## Status

- **R0 Hosting: done** 2026-10-19 (platform-client `hosted`, `platform:local-product --catalog`).
- **R1 Catalogue and counter: done** 2026-10-19. Details and test results in
  `implementation-status.md` ("Retail R0–R1").
- **R2 Purchasing and stock: done** 2026-10-20, without stock locations and holds (below); details in
  `implementation-status.md` ("Retail R2").
- **R3 Customers and credit: done** 2026-10-19; details in `implementation-status.md`
  ("Retail R3").
- **R4 Wholesale documents: done** 2026-10-19; details in `implementation-status.md`
  ("Retail R4"). A delivery note and its invoice are made together: the invoice is always
  exactly what was delivered.
- **R5 Reports: done** 2026-10-20; details in `implementation-status.md` ("Retail R5"). Every
  report shows one industry only, so the shop and the pharmacy at one branch report apart.
- **R6 Gaps: done** 2026-10-23; details in `implementation-status.md` ("Retail R6–R8").
- **R7 Later features: done** 2026-10-23 (same section).
- **R8 Desktop counter: done** 2026-10-23 (same section).
- **Beta: opened** 2026-10-23 (pilot list above).

Open after R2:
- ~~Stock locations (shelves) and quarantine or recall holds are Pharmacy screens only.~~ R6.
- ~~No stock-take count sheets yet.~~ R6.
- Tills are shared with Pharmacy: a member who sells in both uses one cash drawer per branch.
- ~~The shared counter, purchasing and stock screens still name their item prop `medicines`.~~ R6.

Open after R3:
- Customers are Retail's only; Pharmacy has no account sales (its counter offers none).
- No interest, reminders or account blocking when overdue: overdue is shown, not enforced.
- A payment cannot be reversed in the app; a mistaken one needs a correction in Finance.
- Payments are not refunded when a return is larger than what is owed: such a return is refused.

Open after R4:
- Wholesale is on account only: a customer paying cash on delivery needs a credit limit, and pays
  on their statement after the delivery.
- Nothing is reserved by an order: stock and credit are checked when delivering.
- ~~A quotation cannot be edited.~~ R6: a quote is revised in place, repriced at today's prices.
- One price list per customer, and one per branch for the till (R7); no quantity breaks or dated
  prices.
- No tax on invoices yet (`tax_minor` is 0, as at the counter).

Open after R5:
- Reports are per branch: there is no side-by-side of branches yet. Each branch's figures are
  read in turn by switching branch.
- Tills are cashier drawers shared by both industries: the till reconciliation of a branch that
  runs both shows each drawer whole in both industries' reports.
- The reorder list does not suggest quantities; it shows the shortfall, the period's pace and the
  last cost.
- Pharmacy keeps its four reports; the sales-by-category, sales-by-cashier and stock reports are
  the shop's only so far (the controllers are shared, the pages are not).
- The shared report props still name items `medicine` (`rows.*.medicine`, `topMedicines`).

Open after R6–R8:
- Promotions are a percent off an item or a category; no buy-one-get-one, bundles or fixed
  prices. They do not stack: the till takes the biggest one, or the member's own discount.
- Loyalty is one program per workspace, points per spend; no tiers or expiry of points.
- Serial numbers are recorded on the Serial numbers page, not when goods are received; products
  that track them cannot go on a quotation (they are sold at the till).
- The desktop app shows the shop's back-office pages as data (GenericScreen) until each has its
  own screen; it has no return screen yet (returns, with serials, are on the web or the API).
- Desktop sign-in is per product: switching between the pharmacy and the shop signs out first.
- Migrations 2026_10_24_* ran forward on local PostgreSQL; not yet rolled back there.
