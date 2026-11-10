# Miles Petrol (mobile)

The petrol station on a phone, tablet or POS terminal: selling fuel at the
pump, the day's sales and receipts, shifts and tanks, and every other page of
the station from the menu. Expo (SDK 57), Expo Router, NativeWind.

It works online against the workspace's petrol station (`petrol-app`), the
same data as the web. Started as a copy of `restaurant-mobile-app`: sign-in,
branches, the page client, the offline outbox and the SUNMI printer are the
same.

## How screens work

Every screen is a page of the web, fetched as data from the workspace
(`{workspace}/petrol/api/v1/app/<path>`, petrol-app `App\Support\AppPages`):
same controllers, same permissions. A page answers `{component, props, url}`;
an action answers `{message, redirect}`.

Native screens (`src/components/pages/registry.ts`):

| Web page        | Screen                                                   |
| --------------- | -------------------------------------------------------- |
| `home`          | today: sales, litres, low tanks, shifts to approve       |
| `sales/create`  | Sell tab: pump, amount or litres, attendant, payments    |
| `sales/index`   | Sales tab, with sales waiting on this phone on top       |
| `sales/show`    | one sale: print the receipt, void it                     |
| `shifts/index`  | Shifts tab: open a shift with its cash float             |
| `tanks/index`   | Tanks tab: stock and fill level of each tank             |

Every other page (deliveries, cash, reports…) opens from the menu and is shown
as data by `GenericScreen`, with a link to it on the web.

## Selling without a connection

Pages loaded are kept on the phone per workspace, branch and person
(`src/offline/cache.ts`) and shown when the station cannot be reached. The
sell form keeps working: a sale is kept in the outbox (`src/offline/outbox.ts`)
and sent to `POST petrol/api/v1/outbox` (`{id, kind: 'sale.record', payload}`)
when the connection is back.

- The submission id is the sale's idempotency key, so a sale that reached the
  station before the connection dropped is never posted twice.
- The phone works out litres and the total exactly as the station does
  (`App\Support\Volume`), so payments add up to the last minor unit.
- A sale the station refuses (the shift closed, a price missing) waits under
  Outbox, to try again or let go.
- `src/offline/sync.ts` sends on opening, when the connection comes back and
  every 20 s while something waits, and keeps home, the sell form, sales and
  the kit (`GET petrol/api/v1/outbox/kit`) on the phone.

## Printing

"Print receipt" on a sale prints the customer's receipt: through the system
print dialog, or on a SUNMI terminal's own printer through the local
`modules/sunmi-printer` Expo module (Account → Terminal printer: state, test
page, characters per line). The terminal printer needs an Android build
(`npx expo run:android` or EAS), not Expo Go.

## Sign-in

Authorization code with PKCE in the system browser (`EXPO_PUBLIC_AUTH_URL`),
or Google natively; tokens in the secure store, the ID token sent once to
`POST petrol/api/session`, then `petrol/api/v1/*` with the branch in
`X-Branch-Id`. The businesses offered are the person's workspaces entitled to
Miles Petrol.

The app is a public client of the petrol app with the redirect URI
`com.milesoftwares.petrol://oauth`:

```bash
# Locally, from central-app: registers the client and writes PLATFORM_MOBILE_CLIENT_ID into petrol-app/.env
php artisan platform:local-product petrol-app --mobile-redirect=com.milesoftwares.petrol://oauth
```

In production the client is added in the admin (Integrations → Miles Petrol →
a public client with that redirect URI); its id goes into petrol-app's
`PLATFORM_MOBILE_CLIENT_ID` and the app's `EXPO_PUBLIC_CLIENT_ID`. See
`.env.example` for the variables. A phone cannot resolve
`*.milesoftwares.localhost`: point the URLs at the dev PC's LAN address, or use
`EXPO_PUBLIC_DEV_SERVER` with `adb reverse`.

## Icons

`php scripts/draw-icon.php assets/images` draws every variant (app icon,
adaptive icon, splash, logo, favicon): a white pump on fuel orange.

## Builds

`app.json` has no EAS project yet: run `npx eas-cli@latest init` once to make
one under the `konsonaks-team` owner, then `eas build`.

## Layout

```
src/app/            routes: login, choose-business, select-branch, (drawer)/(tabs),
                    page/[...path], account, outbox
src/components/pages/screens/  native screens for petrol-app pages
src/lib/            config, api client, pages, navigation, receipt
src/offline/        kept pages, outbox, sync
src/store/          auth (status, workspace, branch), toast
```
