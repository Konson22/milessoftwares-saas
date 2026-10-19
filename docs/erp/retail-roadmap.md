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
| Later | promotions and loyalty, multiple price levels per branch, serial numbers, SUNMI / offline POS (Phase 11) | — |

## Permissions (catalog 1.3.0)

| Permission | Sensitivity | Scopes |
| --- | --- | --- |
| `retail.products.view` | 1 | organization, branch |
| `retail.products.manage` | 2 | organization |
| `retail.stock.view` | 1 | organization, branch |
| `retail.stock.adjust` | 3 | organization, branch |
| `retail.sales.view` | 1 | organization, branch |
| `retail.sales.create` | 2 | organization, branch |
| `retail.sales.discount` | 2 | organization, branch |
| `retail.sales.void` | 3 | organization, branch |
| `retail.tills.manage` | 3 | organization, branch |
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

Roles: Cashier (sell, own till), Store keeper (products view, stock, purchasing view and receive),
Store manager (everything at the branch, discounts, voids, tills, purchase orders, write-off
approvals, selling on account, customers, orders, price lists), Owner (everything). Cashiers can
look customers up; store keepers see orders and deliver them.
Catalog 1.1.0 (R2) added write-off approval and purchasing; 1.2.0 (R3) added credit and
customers; 1.3.0 (R4) added orders and price lists.

## Pilot list (`planned` → `beta`)

R0–R2 done; a real shop's catalogue loaded (barcodes scanned at the counter on the shop's own
scanner); one week of sales reconciled against Finance; backups restored once.

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

Open after R2:
- Stock locations (shelves) and quarantine or recall holds are Pharmacy screens only.
- No stock-take count sheets yet (in either industry): counts are corrected with per-batch
  adjustments.
- Tills are shared with Pharmacy: a member who sells in both uses one cash drawer per branch.
- Reports are Pharmacy's only and do not separate the two industries yet (R5).
- The shared counter, purchasing and stock screens still name their item prop `medicines`.

Open after R3:
- Customers are Retail's only; Pharmacy has no account sales (its counter offers none).
- No interest, reminders or account blocking when overdue: overdue is shown, not enforced.
- A payment cannot be reversed in the app; a mistaken one needs a correction in Finance.
- Payments are not refunded when a return is larger than what is owed: such a return is refused.

Open after R4:
- Wholesale is on account only: a customer paying cash on delivery needs a credit limit, and pays
  on their statement after the delivery.
- Nothing is reserved by an order: stock and credit are checked when delivering.
- A quotation cannot be edited; cancel it and quote again.
- One price list per customer; no price levels per branch, quantity breaks or dated prices.
- No tax on invoices yet (`tax_minor` is 0, as at the counter).
