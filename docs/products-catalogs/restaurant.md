# Miles Restaurant: product catalogue

Restaurant and café management from Miles Global Softwares: the floor, orders, the kitchen,
bills, tills and drinks stock, for one branch or many. One restaurant, three apps, one set of
data: a waiter's phone, the counter PC and the manager's browser all work on the same orders.

| App | For | Platforms | Name |
| --- | --- | --- | --- |
| **Web** (`restaurant-app`) | managers, cashiers, store keepers, owners | any browser, at `{workspace}/restaurant` | Restaurant |
| **Desktop** (`restaurant-electron-app`) | the counter and the kitchen | Windows, macOS, Linux | Miles Restaurant |
| **Mobile** (`restaurant-mobile-app`) | waiters on the floor, POS terminals | Android phones, tablets and POS terminals (SUNMI and compatible), iOS | Miles Resto (`com.milesoftwares.resto`) |

Every app signs in with the same Miles account and checks the same roles. The desktop and
mobile screens are the web's own pages served as data, so a rule changed on the web holds on
every device.

---

## 1. What it does

### Menu

- Dishes (made to order) and drinks (counted in stock), grouped in sections.
- One price list for the whole organization; changed by the owner.
- Items taken off the menu stop being offered on every device at once.

### Dining areas and tables

- Areas (hall, terrace, VIP) with their tables, per branch.
- The floor shows every table, free or with its open order, and the takeaways; it refreshes
  every 10 to 15 seconds.

### Orders

- One open order per table: a second waiter joins it rather than opening another.
- Takeaway orders.
- Lines with a note for the kitchen ("no onions").
- Sent to the kitchen as a numbered ticket (`KT-000001`); nothing is sent twice.
- A line not yet sent is simply removed; a line the kitchen already has is voided with a
  reason and never billed.
- Move an order to another table; cancel an order.
- The order stays open after sending, so guests can keep ordering until they ask for the bill.

### Kitchen screen

- Tickets oldest first; the kitchen marks them ready, the waiter marks them served.
- Slow tickets are flagged after the branch's set time (20 minutes by default).
- Tickets of a cancelled order show "Stop and clear".

### Bills and payment

- Print the customer bill before payment.
- Payment by method (cash, mobile money, card…) into the cashier's open till, numbered
  (`B-000001`).
- **Split bills**: by line or in equal parts; each part is its own bill and the order closes when
  the last part is paid.
- **Discounts** with a reason, for staff allowed to give them.
- Receipt after payment, printed with the branch's header, phone and footer.
- Void a paid bill whole, with the money given back from the voider's till and the drinks
  returned to stock.
- Mobile pay sheet: quick-cash buttons, change to give, a "to take now" summary.

### Tills and cash

- A cashier's shift opens with a float and closes with a count; a difference needs a note.
- Managers see and close every cashier's till.
- **Cash handovers** between staff, received by a second person.

### Drinks stock

- Stock per branch at average cost; never below zero.
- Opening stock, counts and write-offs.
- **Supplier deliveries** received into stock.
- A bill takes its drinks from stock when paid; a void puts them back. A bill selling more than
  the branch holds is refused.
- Stock movements ledger per branch, and across branches for management.
- Drinks running low on the home screen.

### Reports

- **Day report**: takings, best sellers, voids and staff.
- **Management (all branches)**: sales, kitchens, stock and issues side by side; hourly sales
  charts; period reports; export to Excel (one workbook, a sheet per view including every
  order).

### Branches and settings

- Staff work only in the branches where they hold a restaurant role, choosing their branch once
  per session.
- Owners switch between branches or view "All branches".
- Per-branch settings: receipt header, footer and phone, default starting float, kitchen
  timing.
- Staff page: who may sign in to the restaurant, managed from the Miles account.

---

## 2. Feature availability by app

✓ full · ◐ view only (changes on the web) · — not in this app

| Feature | Web | Desktop | Mobile |
| --- | :-: | :-: | :-: |
| Floor and tables view | ✓ | ✓ | ✓ |
| Take orders, send to kitchen | ✓ | ✓ | ✓ |
| Void lines, move table, cancel order | ✓ | ✓ | ✓ |
| Kitchen screen | ✓ | ✓ | ✓ |
| Take payment, receipts | ✓ | ✓ | ✓ |
| Split bills, discounts | ✓ | ✓ | ✓ (online) |
| Void bills | ✓ | ✓ | ✓ (online) |
| Tills / shifts | ✓ | ✓ | ✓ (online) |
| Cash handovers | ✓ | — | — |
| Menu management | ✓ | ◐ | ✓ (online) |
| Tables and areas setup | ✓ | ◐ | ✓ (online) |
| Drinks stock, deliveries, counts | ✓ | ◐ | ✓ (online) |
| Day report | ✓ | — | — |
| Management overview, Excel export | ✓ | — | — |
| Branch settings, staff access | ✓ | — | — |
| Works without a connection | — | — | ✓ floor actions |
| Printing straight to a POS printer | — | — | ✓ |
| Create a new restaurant from the app | — | ✓ | ✓ |
| Keyboard shortcuts (F1 Floor, F2 Orders, F3 Kitchen, F4 Shifts) | — | ✓ | — |

---

## 3. Working without a connection (mobile)

- Every page opened is kept on the phone and shown when the restaurant cannot be reached.
- Floor work keeps going offline: open an order, add or remove lines, send to the kitchen (the
  paper ticket still prints) and take a whole payment.
- Queued actions are sent oldest first when the connection returns, each applied exactly once;
  if another phone seated the same table meanwhile, the queued work joins that order.
- Anything the restaurant refuses (till closed, item off the menu) waits under **Waiting to
  send**, to retry or let go.
- Menu, tables, stock, voids, cancelling, shifts, split bills and discounts need the
  connection.

The desktop app is online only: when the restaurant cannot be reached the top bar says so and
nothing is lost on the server.

---

## 4. Printing (mobile)

- **Kitchen tickets** print when lines are sent: table, ticket and order number, quantities,
  notes, no prices. Can be turned off per device; any ticket prints again.
- **Customer bill** before payment, **receipt** after (ask / always / never).
- Printers: the built-in printer of SUNMI and compatible POS terminals, a paired Bluetooth
  ESC/POS printer, or the system print dialog (AirPrint / Android printing).
- Printer page: status (paper out, overheated…), test page, width check, sample receipt,
  characters per line (32 on 58 mm paper by default).

The web and desktop print through the browser or system print dialog.

---

## 5. Roles

| Role | Where | What they do |
| --- | --- | --- |
| **Global Admin** | whole organization | everything, including the menu, all branches together and staff access |
| **Restaurant Manager** | their branch | runs the branch: tables, orders and voids, bills, discounts, every till, stock, reports, branch settings |
| **Cashier** | organization or branch | takes orders and payment into their own till, sees bills |
| **Waiter** | organization or branch | sees the menu, takes orders |
| **Store Keeper** | organization or branch | views stock, receives deliveries, records counts |
| **Kitchen Staff** | organization or branch | works the kitchen screen |

### Permissions (catalog 1.3.0)

| Permission | What it allows |
| --- | --- |
| `restaurant.menu.view` | View the menu |
| `restaurant.menu.manage` | Add and change dishes, drinks and prices |
| `restaurant.tables.manage` | Set up dining areas and tables |
| `restaurant.orders.take` | Take table and takeaway orders and send them to the kitchen |
| `restaurant.orders.void` | Void dishes already sent to the kitchen and cancel orders |
| `restaurant.kitchen.view` | Work the kitchen screen |
| `restaurant.bills.view` | View paid bills |
| `restaurant.bills.take` | Take payment into your own till |
| `restaurant.bills.void` | Void paid bills and refund them |
| `restaurant.bills.discount` | Give a discount on a bill, with a reason |
| `restaurant.tills.manage` | See and close every cashier's till |
| `restaurant.stock.view` | View drinks stock |
| `restaurant.stock.adjust` | Record opening stock and stock counts |
| `restaurant.stock.receive` | Receive drinks delivered by suppliers |
| `restaurant.reports.view` | See the day's sales report |
| `restaurant.branch.configure` | Set receipt lines, starting cash, kitchen timing |
| `restaurant.branches.overview` | See every branch together |
| `restaurant.access.manage` | Decide which staff may sign in to the restaurant |

### Built-in controls (separation of duties, warn)

- Voiding bills you took payment for yourself.
- Voiding dishes you ordered yourself.
- One person both selling and adjusting stock.
- Discounting bills you take payment for yourself.

---

## 6. Connected to Miles Finance

Each event reaches Finance in the same transaction as the restaurant's own record:

| Restaurant event | Finance receives |
| --- | --- |
| Bill paid | invoice issued + payment received |
| Discount | a negative invoice line |
| Bill voided | invoice voided |
| Stock count or write-off | stock adjusted |
| Supplier delivery | supplier bill received |
| Till count difference | cash counted (over / short) |

---

## 7. Sign-in and accounts

- One Miles account for every app: email and password (with two-factor), Google, or the
  platform's sign-in page.
- A person sees only the workspaces entitled to the restaurant, and only their branches.
- "Create your restaurant" from the desktop or mobile app opens the account, the business and
  its first branch, with the free trial where offered.

---

## 8. Not yet included

- Recipes and ingredients: dishes carry no cost and take no stock.
- Purchase orders to suppliers (deliveries are received directly).
- Kitchen stations and per-station kitchen printers; drinks share the kitchen ticket.
- Tips and service charge.
- Reports by dish, section, waiter, table and hour; covers and food cost %.
- Offline mode on the desktop.
