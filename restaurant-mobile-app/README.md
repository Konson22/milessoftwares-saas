# Miles Restaurant (mobile)

The restaurant on a phone or tablet: floor, orders, kitchen tickets, bills,
tills, menu, tables and drinks stock. Expo (SDK 57), Expo Router, NativeWind.

On first launch the person chooses how the app works:

| Mode | |
|------|---|
| **Online** | Signs in with the Miles Softwares account and works with the workspace's restaurant (`restaurant-app`), shared by every phone, tablet and PC of the restaurant. Needs a connection. |
| **Offline** | The restaurant lives in a SQLite database on this phone. No account, no connection; staff sign in with a username and password kept on the phone. |

The mode can be switched later from **Account**. Each mode keeps its own data:
switching does not copy anything, and the restaurant on the phone is still
there when the person switches back.

```bash
npm install
cp .env.example .env   # online mode only
npx expo start
npm run typecheck && npx expo lint
```

## How both modes share the screens

Every screen is a restaurant-app page: `{ component, props, url }`, the props
its Inertia page gets. Actions answer `{ message, redirect }` (`redirect` null:
stay and reload). `src/lib/pages.ts` sends a page or an action either to the
workspace (`{workspace}/restaurant/api/v1/app/<path>`) or to the local engine
(`src/local/pages.ts`), which builds the same props from SQLite with the same
rules as restaurant-app's `app/Actions` (Orders, Bills, Tills, Stock):

- one open order per table; opening a busy table opens its order;
- lines not yet sent are removed, sent lines are voided with a reason;
- sending makes a kitchen ticket (`KT-000001`); the kitchen marks it ready, the
  waiter served;
- paying needs every line sent and the cashier's till open; cash tendered must
  cover the total; the bill prices lines at the menu's current prices;
- drinks leave stock at their average cost and stock never goes below zero;
- voiding a bill refunds from the voider's till and puts the drinks back;
- closing a till with a count that differs needs a note.

Online, the floor, kitchen and orders reload every 10–15 seconds.

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

## Online: sign-in

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

## Offline: the restaurant on the phone

Set-up creates the restaurant (name, currency, decimals) and its first
manager, with an optional sample menu and tables. Roles:

| Role | Can |
|------|-----|
| Manager | everything, including staff, settings and backups |
| Cashier | orders, taking payment, their own till |
| Waiter | floor and orders |
| Kitchen | kitchen tickets |

The app copies the database once a day (`restaurant-*.db`, the last 14 kept); a manager can back up now, share a copy off the phone, and restore one under
**Backups**.

## Limits

- Offline, nothing is shared between phones: each phone is its own restaurant.
- Offline has no finance pages; online they stay on the web.
- Bills print through the system print dialog (AirPrint / Android printing),
  not straight to a Bluetooth receipt printer.

## Layout

```
src/app/            routes: welcome (mode), set-up, sign-in (offline), login (online),
                    choose-restaurant, select-branch, (drawer)/(tabs), page/[...path],
                    account, backup
src/components/pages/screens/  one screen per restaurant-app page
src/lib/            config, api client, pages (online/offline dispatch), navigation
src/local/          offline engine: db (schema, session, settings, seed), restaurant rules,
                    pages, backup
src/store/          auth (mode, status, workspace, branch), toast
```
