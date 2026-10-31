# Miles Retail (mobile, offline)

The whole shop on one phone or tablet, with no internet and no account:
the counter (sell by barcode or by name, packs, discounts, receipts,
returns, shifts) and the back office (inventory, batches, receiving
deliveries, products, suppliers, expenses, reports, users, settings).
Everything lives in a SQLite database on the device. Expo + Expo Router +
NativeWind + expo-sqlite.

It is the offline counterpart of the Retail ERP (`retail_trading` in
`../central-app`), for a single shop: a supermarket, a mini-mart, a
hardware or electronics shop.

## Run

expo-sqlite and the Bluetooth printer are native, so this needs a build, not
Expo Go:

```bash
npm install
npx expo run:android            # a dev build on a plugged-in phone
# or a standalone APK (the JavaScript inside, no dev server needed):
npx expo prebuild -p android --clean
cd android && ./gradlew assembleRelease   # android/app/build/outputs/apk/release/app-release.apk
```

The release APK is signed with the debug key: fine for installing by hand,
not for the Play Store (use `eas build` with a real key for that; run
`npx eas-cli@latest init` first, the app has no EAS project of its own yet).

## First run

1. **Set up your shop**: name, address, phone, currency (fixed after this),
   and the first manager's name, username and password. "Add sample
   products" fills the shelf to try selling.
2. The manager adds staff under **Users**: each a username, a password and a
   role (manager, supervisor, cashier).
3. Everyone signs in with their own username. Signing in lasts while the app
   runs; reopening it asks again. A forgotten password is reset by a manager
   under Users.

## Products and stock

- A product is sold by its unit (piece, bottle, kg…) and optionally by packs
  (a box of 12, a crate of 24) at the pack's price.
- Each delivery adds a batch with its cost and, if it has one, its selling
  price. The batch number is optional: without one the shop numbers it
  (LOT000001).
- Expiry dates are off by default; switch on "Has an expiry date" for food,
  drink and the like. Those sell the soonest to expire first, and expired
  stock is never sold. Everything else sells oldest first.
- Low stock is at or below the product's reorder level.

## Backups

Account → Backups (managers), or Settings → Backups.

- A copy is made on its own the first time the app opens each day; the last
  14 are kept (`Documents/backups` in the app's storage).
- **Back up now** makes one by hand; **Share** sends one off the phone (a
  computer, a USB stick, email, a chat with yourself). A copy elsewhere is
  what survives a lost or broken phone.
- **Restore** puts a copy back, from the list or from a file picked on the
  phone. Only Miles Retail backups are accepted (the database carries the
  app's id), so a pharmacy backup cannot replace the shop. What is there now
  is backed up first, and everyone signs in again.

## Receipt printers (Android)

Bluetooth Classic ESC/POS printers (58 and 80 mm): pair the printer in the
phone's Bluetooth settings, then Account → Receipt printer → Choose printer,
Test print. Without one, receipts go through the system print dialog. The
paper width, header and footer are under Settings → The receipt.

## Layout

```
src/app/                    routes (Expo Router)
  set-up, login             first run; username and password
  (drawer)/(tabs)/home|sell|sales|shift
  (drawer)/p/[section]      a back-office section from the menu
  page/[...path]            a record or form opened from a section
  checkout, receipt/[id], return/[id], held, scan
  account, backup           who is signed in, printer, backups
src/local/db/               the database: schema, counter, pages (back office),
                            expenses, session (scrypt passwords), seed
src/local/handlers.ts       the calls the app makes, by name
src/local/backup.ts         daily copies, share, restore (VACUUM INTO)
src/lib/api/                call(name, ...args) → the local handlers
src/lib/pages.ts            back-office pages as data: getPage(path), act(...)
src/components/pages/       PageView and the native screens for each page
modules/bluetooth-printer/  Android native module: print over Bluetooth SPP
```

A back-office page is `{ component, props }` from `src/local/db/pages.ts`;
`src/components/pages/registry.ts` maps each component to its screen, and an
action (`act('POST', 'batches/<id>/hold', …)`) answers a message and,
for forms, the page to go to next.

## Not here

- Syncing between devices: each phone is its own shop. To move to another
  phone, share a backup and restore it there.
- Customers on credit, quotations and invoices, stock locations, transfers
  and counts (the online Retail ERP has them).
- Bluetooth printing on iOS.
