# Miles Pharmacy Offline (single branch)

The Miles Softwares pharmacy for one shop on one Windows, macOS or Linux PC.
It has its own database and needs no internet, server or platform account.
Electron + React + Tailwind (the shared theme from `../packages/ui`).

This is the standalone sibling of `../pharmacy-electron-app`, which is the
online counter of the workspace pharmacy (`../pharmacy-app`). The two share
screens, but this one keeps everything on the PC.

## Run

```bash
npm install
npm run dev              # the app, with hot reload
npm run typecheck
npm run dist:win         # installer in dist/ (dist for this OS)
```

If `npm run dev` says Electron failed to install, fetch its binary:
`node node_modules/electron/install.js`.

On first run the app asks for the pharmacy (its name, address and currency)
and its first manager. It can also add sample medicines with stock to try
it. After that, people sign in with a username and password.

Keys: F1 Sell, F2 Sales, F3 Shift, F4 Prescriptions, `/` to search. A
barcode scanner types into the search and presses Enter, which puts the
medicine in the cart.

## What it does

| | |
|---|---|
| Counter | sell (FEFO: soonest-to-expire batch first), packs, discounts, held carts, receipts, void and return |
| Shifts | open with a float, cash in and out, close with a count (blind for cashiers if set) |
| Prescriptions | record, verify (pharmacist), reject, dispense in part or whole; prescription-only medicines sell only this way |
| Inventory, batches | stock per medicine and every batch with its cost, selling price and margin; add, adjust, dispose, hold back, release, reprice, with every movement logged |
| Receive stock | deliveries put batches in stock: bought by the unit or by the box (a box of 10 at USD 100), in the pharmacy's currency or the second one; a unit's cost is worked out, and its selling price set |
| Pricing | each batch sells at its own price, soonest to expire first; new stock at a new price leaves older batches at theirs; prices also show in the second currency at the rate under Settings |
| Medicines, suppliers | the catalog with packs, barcodes, reorder levels |
| Expenses | by category, each operating, recurring (with how often, so bills show as due or overdue) or one-off; paid from the safe, the bank or mobile money by a manager, or from the till during a shift (categories marked "paid from the till"), which counts against that shift's drawer; taken back with a reason |
| Reports | net sales, cost, gross profit, expenses by category and net profit, by day, by payment, by person, best sellers, shifts over or short, stock value, low and expiring |
| Users | manager, pharmacist or cashier; a manager resets forgotten passwords |
| Settings | the second currency and its exchange rate, the receipt, payment methods, discount limit, shifts, customer rules |
| Backups | daily automatic copy (last 14 kept), back up now, save to a USB stick, restore |

Roles: a cashier sells, records prescriptions and sees stock. A pharmacist
also discounts, verifies prescriptions, and manages medicines, stock,
suppliers and deliveries. A manager can do everything, including voids,
returns, expenses, reports, users, settings and backups. Anyone with a shift
open can pay an expense from the till, under a category marked for it. There must always be at
least one active manager.

## Data

- **The database** is `pharmacy.db` (SQLite, WAL) in the app's user-data
  folder, for example `%APPDATA%\Miles Pharmacy Offline` on Windows. Only
  the main process opens it, through `node:sqlite`, which is built into
  Electron. No native module is needed.
- **Backups** are under `backups/` in the same folder. Each is a whole
  database made by `VACUUM INTO`. Restoring first keeps what is there now as
  a "before a restore" copy, then signs everyone out.
- **Schema** changes are migrations in `src/main/db/schema.ts`, applied by
  `PRAGMA user_version`. The app refuses a database made by a newer version.
- **Amounts** are integers in the currency's minor unit. The number of
  decimals is set at setup and cannot change afterwards. Amounts entered in
  the second currency are stored in the pharmacy's own; a batch also keeps
  what it was bought for as invoiced (pack, currency, price, rate).
- **Prices** are worked out in one place, `src/shared/pricing.ts`, used by
  both the cart and the sale, so the total shown is the total charged.
- The cart and held carts are kept separately in `data/*.json` (written
  atomically), so a restart never loses a sale in progress.

## Layout

```
src/main/
  index.ts         window, IPC, printing; opens the database, daily backups
  handlers.ts      what the window may call, by name
  backup.ts        backups: daily, now, to a file, restore
  db/
    schema.ts      tables and migrations
    database.ts    open, queries, transactions, numbering
    session.ts     users, passwords (scrypt), roles and permissions, first-run set-up
    settings.ts    the pharmacy's settings
    catalog.ts     medicines as sold: sellable stock, FEFO
    counter.ts     shifts, sales, voids, returns, prescriptions
    expenses.ts    expense categories, expenses, recurring bills due
    pages.ts       the back office: dashboard, stock, medicines, suppliers, goods received, reports, users, settings
    seed.ts        sample medicines
src/preload/       window.desktop, the bridge (types in src/shared/ipc.ts)
src/shared/        types shared by both sides
src/renderer/src/
  lib/api/         the counter's calls (api.sell(…) → handlers.ts)
  lib/pages.ts     back-office pages and actions
  store/           session, cart (held carts), ui
  pages/           set-up, login, sell, sales, shift, prescriptions
  screens/         back-office screens (registry.tsx)
  components/      shell, cart, checkout, ui kit
```

## Not yet

- Purchase orders, supplier returns, the controlled-drugs register and
  stock counts.
- Silent printing to a chosen receipt printer (prints use the system dialog).
- Auto-update and code signing for the installer.
- A licence key: the app does not check one.
