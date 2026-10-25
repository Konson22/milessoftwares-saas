# Restaurant roadmap

Restaurant is its own application, `restaurant-app`, served at `{workspace}/restaurant` with its
own database and deployment ([ADR-0007](../adr/0007-restaurant-application.md)). It is the
registry application `restaurant` (catalog `restaurant-app/platform/catalog.json`). It keeps its
own menu, drinks stock, tables, orders, kitchen tickets, tills and bills, and tells Finance of
each bill, void, stock change and till count through the shared event contracts.

Every phase: tests, Pint, PHPStan, `tsc` and build green; migrations rolled back and forward;
results in `implementation-status.md`. The application stays `planned` (shown as coming soon,
not sold) until the pilot list below is met.

## What a restaurant sells

| Kind | What | Stock |
| --- | --- | --- |
| `dish` | made to order (a plate of rice and beans, a tea) | none; from T3 its recipe's ingredients are taken |
| `drink` | bought and sold as it is (a soda, a bottle of water) | counted per branch at an average cost (`stock_levels`) |
| `ingredient` (T3) | bought and kept, never sold at the table (rice, oil, beans) | as drinks |

A bill line of a dish costs nothing until T3 gives it its recipe's cost; a drink's line costs its
average cost at the branch when the bill is paid.

## Phases

| Phase | Scope | Acceptance |
| --- | --- | --- |
| T0 Application | `restaurant-app` from the product template (port 8700, database `restaurant`), catalog 1.0.0, branch choice, layout and home | a member without restaurant access gets 403; a member works only in branches where they hold a restaurant role |
| T1 Menu, tables, orders, kitchen and bills | the menu (dishes and drinks, sections, prices); dining tables by area; an order per table or a takeaway; lines with a note for the kitchen; send to the kitchen as a ticket; the kitchen screen marks tickets ready and served; void a sent line with a reason; move an order to another table; tills (float, count, over/short); the bill paid into the cashier's open till; voiding a bill; drinks stock (adding, counts, write-offs) | a table has one open order at a time; nothing is sent to the kitchen twice; a voided line is never billed; a bill, its stock and its Finance events commit together; a paid order cannot change; drinks fall when the bill is paid and come back when it is voided; a bill selling more drinks than the branch holds is refused |
| T2 Bills | printed bill before payment, split bills (by line or in equal parts), tips and a service charge, discounts within the member's limit, merging tables | the parts of a split bill add up to the order; a tip reaches Finance apart from the takings; the order is closed only when every part is paid |
| T3 Recipes, ingredients and purchasing | ingredients, a recipe per dish; selling a dish takes its ingredients' stock and costs it; suppliers, purchase orders and receiving (posting `finance.bill.received`) | a dish's cost equals its ingredients' average cost; a void puts the ingredients back; a dish whose ingredients are out still sells (the shortfall is reported, not refused) unless the branch says otherwise |
| T4 Reports | sales by dish, section, waiter, table and hour; covers and average bill; food cost %; voids by reason | totals equal the restaurant's bills and Finance postings for the period |
| T5 Devices | the order API at `{workspace}/restaurant/api/v1` for a waiter's handheld (SUNMI) and the desktop counter; kitchen printers per station | a waiter's token never calls another product's API; a ticket queued offline is sent once |

## Permissions (catalog 1.0.0)

| Permission | Sensitivity | Scopes |
| --- | --- | --- |
| `restaurant.menu.view` | 1 | organization, branch |
| `restaurant.menu.manage` | 2 | organization |
| `restaurant.tables.manage` | 2 | organization, branch |
| `restaurant.orders.take` | 1 | organization, branch |
| `restaurant.orders.void` | 3 | organization, branch |
| `restaurant.kitchen.view` | 1 | organization, branch |
| `restaurant.bills.view` | 1 | organization, branch |
| `restaurant.bills.take` | 2 | organization, branch |
| `restaurant.bills.void` | 3 | organization, branch |
| `restaurant.tills.manage` | 3 | organization, branch |
| `restaurant.stock.view` | 1 | organization, branch |
| `restaurant.stock.adjust` | 3 | organization, branch |

Roles: Waiter (menu view, take orders), Kitchen (menu view, kitchen screen), Cashier (take
orders, take payment into their own till, bills), Restaurant manager (everything at the branch:
voids, tables, every till, stock; the menu). Separation of duties (warn): taking and voiding
bills, taking orders and voiding them, taking bills and adjusting stock.

## Pilot list (`planned` → `beta`)

T0–T2 done; one restaurant's menu and tables loaded; a week of bills reconciled against Finance;
backups restored once.

## Status

- **T0 Application: done** 2026-10-25. Details in `implementation-status.md` ("Restaurant T0–T1").
- **T1 Menu, tables, orders, kitchen and bills: done** 2026-10-25 (same section).
- **T5 Devices, started** 2026-10-25: the desktop app (`restaurant-electron-app`) and its API.
  The API is `GET me` plus every web page as data at `{workspace}/restaurant/api/v1/app/<web path>`
  (`App\Support\AppPages`), signed in with the app's token, with the branch in `X-Branch-Id`.
  The desktop covers the floor, orders, the kitchen screen, shifts and bills, and creating a
  restaurant. Still open: offline queueing of orders, kitchen printers per station, the SUNMI
  handheld.

Open after T1:
- Not registered with the platform yet: `php artisan platform:local-product restaurant-app
  --url=http://127.0.0.1:8700` (central-app) fills its clients; until then `/restaurant` cannot
  sign in.
- One kitchen per branch: drinks go on the same ticket as dishes (stations and bar tickets: T5).
- A bill is one payment: no split bills, tips, service charge or discounts yet (T2).
- No printed kitchen ticket or pre-payment bill (T2, T5); the receipt prints from the bill page;
  the kitchen screen refreshes every 15 s.
- A bill is voided whole; dishes cost nothing until T3; drinks come in only as added stock until
  purchasing (T3).
- Every bill needs the cashier's open till at the branch (there is no shift-free mode).
