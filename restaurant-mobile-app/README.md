# Mataem Plus (mobile)

The restaurant on a phone or tablet: floor, orders, kitchen tickets, bills,
tills, menu, tables and drinks stock. Expo (SDK 57), Expo Router, NativeWind.

The app signs in with the Miles Softwares account and works with the
workspace's restaurant (`restaurant-app`), shared by every phone, tablet and
PC of the restaurant. (An offline mode, with the restaurant in SQLite on the
phone, was removed on 2026-11-01; it is in the git history.)

```bash
npm install
cp .env.example .env
npx expo start
npm run typecheck && npx expo lint
```

## Screens are restaurant-app pages

Every screen is a restaurant-app page: `{ component, props, url }`, the props
its Inertia page gets. Actions answer `{ message, redirect }` (`redirect` null:
stay and reload). `src/lib/pages.ts` sends a page or an action to the
workspace (`{workspace}/restaurant/api/v1/app/<path>`); the rules are
restaurant-app's `app/Actions` (Orders, Bills, Tills, Stock).

The floor, kitchen and orders reload every 10–15 seconds.

## Working without a connection

Every page loaded is kept on the phone (`src/offline/cache.ts`, per workspace,
branch and person) and shown when the restaurant cannot be reached. The floor's
own actions keep working then: opening an order, adding and taking off lines,
sending to the kitchen (the paper ticket still prints) and taking payment. They
wait in the outbox (`src/offline/outbox.ts`) and change the kept floor and order
at once (`src/offline/local.ts`). Everything else (menu, tables, stock, voids,
cancelling, shifts) needs the connection.

- Each action has a submission id; restaurant-app `POST api/v1/outbox` applies it
  once and answers a repeat with the first answer. Orders and lines keep the ids
  the phone made; a table another phone seated meanwhile gives its order instead,
  and what follows on the phone moves to it.
- Sent oldest first (`src/offline/sync.ts`): on opening, when the connection comes
  back, every 20 s while something waits; the floor, its open orders and
  `GET api/v1/outbox/kit` (menu, payment methods, till) are kept fresh every five
  minutes.
- What the restaurant refuses (a till closed, an item off the menu) waits under
  **Waiting to send** (tap the bar under the header) to be tried again or let go;
  later actions on the same order wait with it.
- Online with nothing waiting, these actions still go through `api/v1/outbox`, so a
  dropped answer is never applied twice.
- Bills paid offline get the restaurant's time and number when they are sent, and
  need the cashier's till still open then.

## Printing

- **Kitchen tickets:** when lines are sent, the phone prints their ticket (table,
  ticket and order number, quantities, notes, no prices). It can be turned off
  per phone under Account → Printing; any ticket prints again from the order or
  the kitchen screen.
- **Customer bill:** while the order is open, "Print customer bill" on the order.
  The order stays open after sending, so the customer can keep ordering
  ("Add more items") until they ask for the bill.
- **Receipt:** after payment the app offers the paid receipt; it prints again from
  the order or the bill.

### POS terminals (SUNMI built-in printer)

On a SUNMI terminal (V2s and the like) the app prints on the terminal's own
printer, through the local `modules/sunmi-printer` Expo module (the SUNMI
printer service, `com.sunmi:printerlibrary`), copied from the Manggala POS
app with its printing service (`src/lib/terminal-printer`). Nothing to pair:
Account → Terminal printer shows its state (paper out, overheated…), prints a
test page and a width check, and sets the characters per line (32 on 58 mm
paper by default). It can be turned off there to print another way. A job the
printer does not confirm says so: check the paper before printing again.
It needs an Android build (`npx expo run:android` or EAS), not Expo Go.

## Sign-in

Authorization code with PKCE in the system browser (`EXPO_PUBLIC_AUTH_URL`),
tokens in the secure store, the ID token sent once to
`POST restaurant/api/session`, then `restaurant/api/v1/*` with the branch in
`X-Branch-Id`. The restaurants offered are the person's workspaces entitled to
the restaurant.

The app is a public client of the restaurant with the redirect URI
`com.milesoftwares.restaurant://oauth`:

```bash
# Locally, from central-app: registers the client and writes PLATFORM_MOBILE_CLIENT_ID into restaurant-app/.env
php artisan platform:local-product restaurant-app --mobile-redirect=com.milesoftwares.restaurant://oauth
```

In production the client is added in the admin (Integrations → the restaurant
→ a public client with that redirect URI); its id goes into the restaurant's
`PLATFORM_MOBILE_CLIENT_ID` and the app's `EXPO_PUBLIC_CLIENT_ID`. See
`.env.example` for the variables. A phone cannot resolve
`*.milesoftwares.localhost`: point the URLs at the dev PC's LAN address, or use
`EXPO_PUBLIC_DEV_SERVER` with `adb reverse`.

## Limits

- Away from a SUNMI terminal, bills print through the system print dialog
  (AirPrint / Android printing), not straight to a Bluetooth receipt printer.

## Layout

```
src/app/            routes: login, choose-restaurant, select-branch, (drawer)/(tabs),
                    page/[...path], account, outbox
src/components/pages/screens/  one screen per restaurant-app page
src/lib/            config, api client, pages, navigation
src/offline/        kept pages, outbox, floor actions on the phone, sync
src/store/          auth (status, workspace, branch), toast
```
