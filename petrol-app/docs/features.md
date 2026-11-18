# Petrol Station features

Fuel operations for one Miles workspace, served at `{workspace}/petrol`. Sign-in, companies, branches, roles and billing stay on the platform. This app keeps the station's own database: tanks, pumps, shifts, sales, purchases and cash.

Code `petrol`. Catalog version `1.5.0`. Dev port **8900**. The phone, tablet, and POS app (`petrol-mobile-app`) reads the same pages at `{workspace}/petrol/api/v1/app/<path>`. What it can do on its own is in [Mobile app](#mobile-app).

Company-wide pages (Home is per station; Stations, Staff, Inventory, and the operations dashboard) do not require a station to be selected. Everything else is the station currently open.

## Dashboard

**Home** shows the open shift, today's posted sales, tanks at or below the station's low-stock threshold, shifts waiting for approval, and credit still owed. A branch that is not a station yet can be set up from here.

**Operations dashboard** is company-wide, for the branches the member may report on. Totals are in the workspace currency. It covers sales against the previous period, litres, profit, stock quantity and value, cash and credit, approved shortages and overages, tank variance, open shifts, and shifts waiting for approval. It also lists each station, product mix, payment mix, who is on duty, and alerts. Cash taken to the office is not added to sales. An open shift that has not been approved is not a shortage.

## Management

**Stations** lists every station the member can see, with the same company figures as the dashboard.

**Staff** lists attendants across stations. A manager can add and change them. The plan caps attendants only when it includes `petrol.attendants`.

**Inventory** is the company view of fuel in tanks and non-fuel stock in stores.

## Operations

**Sales** record litres (or an amount) against a nozzle on the open shift. Payments must add up to the price. Methods are cash, mobile money, bank transfer, and credit. The same idempotency key never posts twice. A sale cannot take the tank below zero. Voiding returns the litres and reverses the cash or credit. A sale paid with more than one non-credit method cannot be voided here, because Finance refunds one method. Cash plus credit can be voided; the refund is the cash portion.

**Shifts** open with a cash float, take nozzle assignments, and close through submit, review, approve, reject, or reopen. Statuses are open, closing submitted, under review, approved, and rejected. The person who submitted the closing cannot approve it. Meter readings can be corrected before approval.

**Assignments** put an attendant on a nozzle for a span of time. Closing an assignment does not close the station shift and does not move that attendant's cash. An attendant can be sent on a break and brought back, reassigned, or marked as departed.

**Readings** are the opening and closing meter readings for the shift.

**Reconciliation** compares metered litres with recorded sales, and the cash the shift should hold with the cash it has. The figures are derived from the rows, then copied onto the shift when it is approved.

**Handovers** pass a nozzle from one attendant to the next: requested, verified by the incoming attendant, then approved or rejected.

**Exceptions** stay on the shift after the reading or the cash is corrected. They move through open, under review, resolved, and closed, and a closed one can be reopened. Severity is warning or critical.

**Service bay** is non-fuel work. A supervisor sets up bays and priced services. A technician records the vehicle, opens a work order, adds labour and parts, issues a part from the store (once), advances the job, and takes payment. A part on an estimate does not leave the store until it is issued. Job statuses are open, in progress, completed, invoiced, closed, and cancelled.

## Fuel

**Products** are the fuel grades, with the selling price for the station. A new price does not rewrite sales already recorded.

**Tanks** hold one grade. A tank is underground or aboveground, and active, out of service, or in maintenance. Opening stock is posted once, under a row lock, with the movement ledger. A dip (volume, dipstick, or gauge) stores the book quantity beside the physical quantity and does not itself change stock. A second person verifies it. An approved difference can then be posted as an adjustment. Water found in a tank can be cleared after verification. Depth-to-volume charts convert a dip to litres, piecewise between the points the station supplied. A new chart version leaves older measurements unchanged.

**Deliveries** stay pending until a second person accepts a quantity. The measured quantity is what enters the tank. Sales during unloading are subtracted from the expected dip, using the stock movements those sales already posted. Meters are not deducted again. A delivery can also be received against a purchase order.

**Calibration** is pump proving. A test draw is recorded, approved, and returned to the tank. Test fuel is a stock movement, never a customer sale. Returning it posts one addition. Equipment events cover meter replacement, reset, rollover, and calibration.

**Pumps** group nozzles. Each nozzle draws from one tank and can be active or out of service. A pump can be active, in maintenance, or out of service.

**Transfers** move fuel or store stock between stations of the same company. The path is requested, approved, dispatched, received, and completed. Stock moves on dispatch and on receipt, each exactly once. Litres still on the road are the dispatched quantity that has not been received.

## Business

**Attendants** belong to a station. The same person can be listed from the company Staff page.

**Customers** are individual, corporate, NGO, government, or fleet. Credit sales increase what they owe; a payment reduces it. The balance is the sum of the ledger. Collecting a payment needs an open shift and a payment method Finance recognises.

**Suppliers, purchases, and receipts.** An order is ordered, partial, received, or cancelled. Receiving posts the measured litres into the tank and tells Finance a bill arrived. A payment recorded here is operational: Finance still shows the payable until someone pays it there.

**Store** is non-fuel stock (parts, lubricants, shop goods). Balances change only through a movement, and a movement key cannot post twice. Movements are receive, issue, adjust, count, and transfer in or out.

## Finance

**Expenses** are paid from the drawer, petty cash, or the bank. Categories are recurring or one-off, and creating a category is an organisation permission. A schedule (daily, weekly, monthly, quarterly, yearly) is not itself an expense. Generating it posts one unpaid bank expense per due date. A missed run catches up one date at a time. A schedule can be paused, resumed, or ended. An expense paid from the drawer is sent to Finance. Moving money between the drawer and the petty box stays inside the station and is not sent.

**Cash** is physical custody: attendant, shift drawer, safe, and bank.

- The drawer records the float, sales, voids, expenses, customer payments, supplier payments, and petty movements.
- An attendant submits a remittance. Someone else confirms or rejects it. Pending cash stays with the sender. A confirmed handover is not a sale.
- Safes can be opened, received into, and moved between. Payouts and approved adjustments are separate.
- A bank deposit is drafted, prepared, submitted, then confirmed or rejected.
- A cash count reconciles what is in hand. An approved shift closing tells Finance the cash that was counted.

**Finance overview** is the station's own period view: sales, cost, expenses, profit, receipts, and approved shifts. It is not the Finance application. The note on the page is the supplier-payment limit above.

## Reports and audit

**Reports** cover a date range: posted sales and litres, cost, expenses, profit, sales by nozzle and by attendant, receipts, cash differences on approved shifts, and tanks with the low-stock flag.

**Audit** is an append-only history of stock and money in the station database. The platform keeps its own audit log.

## Settings

**Station** covers the station code, hours, whether it is active, the low-stock percent, how cash is collected (attendant, cashier, or both), and whether variances are judged from sales or from meters. Shift schedules and payment methods are set here. The plan caps stations only when it includes `petrol.stations`.

Exactly one station in a company is the Main Branch. The branch row stays in Miles Central. The flag does not grant access, and it does not move tanks, shifts, or sales off the branch that recorded them.

**Currencies.** SSP and USD are created when the company catalog is first read. Further currencies are managed at organisation level. A station that has not chosen yet accepts every active company currency. Enabling a currency at a station is a separate permission.

**Exchange rates** are per station. A new rate closes the previous one. It does not rewrite sales or expenses that already copied a rate. Amounts sent to Finance are converted into the home currency with that rate.

## Who can do what

Five roles (catalog 1.6.0). All are delegable.

| Role | Scope | What they do |
| --- | --- | --- |
| Global admin | Organisation only | Every permission at every station. |
| Branch manager | Branch only | Everything at their station: sales and voids, shifts and approvals, pumps, tanks and stock, receiving, prices, cash and safes, purchases and supplier payments, staff, settings and reports. Not the company-wide currency list or expense categories. |
| Accountant | Organisation or branch | The former cashier, accountant, inventory officer and storekeeper in one: sells and runs the drawer, customers and credit, cash counts, deposits and adjustments, expenses, supplier payments, finance, reports and audit, tanks, dips and charts, receiving and stock adjustments, the store, and transfer requests. |
| Service bay attendant | Organisation or branch | Opens and completes work orders, and looks up customers. Bays and services are set up by a manager. |
| Attendant | Organisation or branch | Mobile app only. Sells, views the shift, submits cash, starts and confirms a handover, and records meter readings and dips. A member whose only role is Attendant gets a "use the mobile app" page on the web (`RequireWebRole`). |

The Accountant role holds both selling and stock adjustment, so assigning it shows that warning.

**Moving to 1.6.0 on a live platform.** Publishing the catalog changes nothing for people who already hold a role. Run each step with `--dry-run` first:

```
php artisan platform:catalog-reassign petrol
php artisan platform:catalog-retire-role petrol manager --scope=organization --map=admin
php artisan platform:catalog-retire-role petrol cashier --map=accountant
php artisan platform:catalog-retire-role petrol inventory_officer --map=accountant
php artisan platform:catalog-retire-role petrol storekeeper --map=accountant
php artisan platform:catalog-retire-role petrol service_supervisor --map=service_attendant
php artisan platform:catalog-retire-role petrol technician --map=service_attendant
php artisan platform:catalog-retire-role petrol supervisor --map=manager
php artisan platform:catalog-retire-role petrol auditor
```

`catalog-reassign` moves branch managers, accountants and attendants to the new definitions. Organisation-wide station managers become Global admins. A supervisor at organisation scope cannot become a Branch manager and is only revoked; an auditor gets nothing in place. Give those people a role by hand.

These pairs are warned, not blocked, when one person holds both:

- Recording a sale and voiding a sale.
- Selling and adjusting stock.
- Ordering fuel and paying the supplier.
- Submitting a shift closing and approving it. The shift service still refuses self-approval.
- Submitting a cash remittance and confirming it.

## Plan limits

| Feature | What it caps |
| --- | --- |
| `petrol.stations` | How many stations the plan includes. Applied only when the plan lists the feature. |
| `petrol.attendants` | How many attendants the plan includes. Applied only when the plan lists the feature. |

User limits and billing stay in the platform.

## Mobile app

`petrol-mobile-app` is the station on a phone, tablet, or POS terminal (Expo). It uses the same controllers and the same permissions as the web. A tab or drawer item appears only when the signed-in person holds the permission for it. An owner sees every section.

### Sign-in and the business

Sign-in is online. Email and password stay in the app, including a two-step code or a recovery code when the account has one. Google uses the device account picker when the build has Google's client IDs. Accounts that still need something done on the platform open the platform's page in the browser.

One business opens straight away. More than one asks which to open. None yet leads to creating one in the app: a Miles Softwares account, then the station. The person becomes its owner, on the station's free trial, with a first branch named Main branch, and the app opens in that branch.

The station worked in is chosen from the branches where the person has a petrol role. Account can switch branch when there is more than one. Signing out while sales are still waiting asks first. Those sales stay on the phone and are sent the next time that person signs in at that branch.

### Everyday screens

Five tabs sit at the bottom. A tab the person may not use is hidden.

| Tab | What it does |
| --- | --- |
| Home | Greeting, online or not, the open shift, today's sales and litres, low tanks, shifts waiting for approval, and credit still owed. Each figure opens the section it comes from. Shortcuts, for whoever may use them: sell, shifts, tanks, deliveries, and cash. A branch with no station yet says an owner sets it up on the web. |
| Sell | The pump sale. Pick the nozzle (only nozzles on the open shift), enter an amount or litres, pick who served, and how it was paid. When the station collects either way, say whether the attendant or the cashier took the money. One payment, or a split that must add up to the total. Credit needs a customer, searched by name. Mobile money and bank transfer can carry a reference. Litres and the total are worked out on the phone the same way the station does, so the payments match to the last minor unit. With no open shift, the screen points at Shifts. |
| Sales | Latest sales at this branch, newest first, with voided ones marked. Sales still on this phone sit above the list. The list reloads every 30 seconds while it is open. |
| Shifts | The branch's shifts. Someone who may open one does it here: cash float, an optional schedule, and an optional handover note. Opening readings start from the last closing. Changing them is on the shift's page, which is the web page's data. |
| Tanks | Each tank's grade, litres against capacity, fill bar, average cost per litre, and a low or out-of-service mark. A tank opens its page as data. Tanks themselves are added on the web. |

One sale shows the pump, litres, price, attendant, time, total, and each payment. **Print receipt** prints it. **Void sale** asks for a reason, returns the fuel to the tank, and reverses the payment. Voiding needs the connection and the void permission.

Pull to refresh on these screens. The header shows the business, the branch, and whether the station answers. A bar under it appears when the phone is offline, when something is waiting to send, or when the station refused something. Tapping it opens the outbox.

### The rest of the station

The drawer, from the right, lists the same groups as the web menu, limited to what this person may open: operations dashboard, stations, staff, inventory, assignments, readings, reconciliation, service bay, products, deliveries, calibration, handovers, exceptions, pumps, transfers, attendants, customers, suppliers, purchases, store, expenses, cash, finance, reports, audit, and station settings.

Those pages are the web page's data, read-only, with a link that opens the same page on the web in the browser. Changing them (a delivery, a cash count, a price, a handover) is done on the web. Currencies and exchange rates are not in the phone's menu.

### Working without a connection

Pages already loaded are kept on the phone, one copy per workspace, branch, and person, and shown when the station cannot be reached. While online, the phone refreshes home, the sell form, and the sales list every five minutes, and also keeps the sell kit: currency, payment methods, and customers (`GET /petrol/api/v1/outbox/kit`).

The sell form keeps working offline. The sale is stored on the phone and sent to `POST /petrol/api/v1/outbox` as `{id, kind: 'sale.record', payload}` when the connection is back. The phone makes the submission id, and that id is the sale's idempotency key, so a sale that reached the station before the connection dropped is never posted twice.

Sending runs on opening the app, when the connection returns, and every 20 seconds while something waits. With no connection the phone asks the station every 15 seconds whether it answers again. **Send now** on the outbox screen sends immediately.

The outbox lists what is waiting, what the station refused (try again, or let it go), and the last things sent. Letting go means the station never hears of it. Signing out does not delete the queue. A refused sale (the shift closed, a price missing) stays until a person retries or drops it. The station also accepts `cash.collect`, `expense.record`, `fuel.receive`, `shift.open`, and `cash.remit` on that same outbox. The phone itself only queues sales.

Two phones can both sync. The tank lock and the shift reconciliation catch the result. There is no separate stock database per device.

### Printing

Print receipt uses the system print dialog: a Wi-Fi or Bluetooth printer the phone knows, or a PDF. On an Android POS terminal with the SUNMI printer module, Account can print on the terminal instead: turn it on or off, see the printer state, print a test page, and set characters per line. That printer needs an Android build, not Expo Go.

## What this version does not do

- A sale paid with more than one non-credit method cannot be voided here.
- Supplier payments recorded here do not settle the bill in Finance.
- Moving cash between the drawer and petty cash is not sent to Finance.
- The offline outbox does not merge two devices editing the same shift. A second open shift is refused.
- On the phone, only a fuel sale is queued offline. Opening a shift, voiding, and every other change need the connection. Pages other than home, sell, sales, shifts, and tanks are read-only, with the change made on the web.
- The phone does not set up a station, add a tank, or manage currencies and exchange rates.
