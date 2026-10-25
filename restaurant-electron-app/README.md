# Miles Restaurant (desktop)

The workspace restaurant (`../restaurant-app`) on a Windows, macOS or Linux PC:
the floor, orders sent to the kitchen, the kitchen screen, the cashier's
shift and bills. Electron + React + Tailwind (the shared theme from
`../packages/ui`). Built from `../pharmacy-electron-app`'s sign-in and page
bridge.

## Run

```bash
cp .env.example .env     # then fill VITE_CLIENT_ID (below)
npm install
npm run dev              # the app, with hot reload
npm run typecheck
npm run dist:win         # installer in dist/ (dist for this OS)
```

If `npm run dev` says Electron failed to install, fetch its binary:
`node node_modules/electron/install.js`.

Keys: F1 Floor, F2 Orders, F3 Kitchen, F4 Shifts.

## What it does

| Screen | Web page it draws | Who sees it |
|---|---|---|
| Home | `home`: open orders, today's takings, drinks running low | everyone at the branch |
| Floor | `floor`: tables by area, free or with their order; takeaways. Refreshed every 15 s | `restaurant.orders.take` |
| Order | `orders/show`: the menu to add from (notes for the kitchen), send to the kitchen, take off or void a line, move table, cancel, print the bill, take payment | `restaurant.orders.take`; voids `…orders.void`; payment `…bills.take` |
| Orders | `orders/index`: a day's orders | `restaurant.orders.take` |
| Kitchen | `kitchen`: tickets oldest first, ready, then served. Refreshed every 10 s | `restaurant.kitchen.view` |
| Shifts | `tills/index`, `tills/show`: start with a float, count and close | `restaurant.bills.take` or `…tills.manage` |
| Bills paid | `bills/index`, `bills/show`: print again, void | `restaurant.bills.view`; void `…bills.void` |
| Menu, Tables, Drinks stock | shown as data, with "Open on the web" to change them | as on the web |

Every screen is a web page of the restaurant as data: `GET
{workspace}/restaurant/api/v1/app/<web path>` answers the page's component and
props, and an action answers its message and the page to go to next
(restaurant-app `App\Support\AppPages`, `ServeInertiaAsJson`). The same
controllers and permissions as the web run them, so a rule changed on the web
holds here too. The branch travels as `X-Branch-Id` on every call.

The app needs the connection: an order goes to the kitchen and to the other
waiters the moment it is sent, and nothing is kept on this PC to send later.
When the restaurant cannot be reached, the top bar says so and actions answer
with an error; nothing is lost on the server.

## Sign-in and a new restaurant

The main process (`src/main`) holds the tokens and makes every call, as in the
pharmacy desktop (`../pharmacy-electron-app/README.md`, "Live mode and
sign-in"): email and password (with two-factor), Google, or the platform's page
in the browser, all with the authorization code flow and PKCE. The browser
paths come back to `http://127.0.0.1:48218/oauth`.

"New to Miles? Create your restaurant" creates the account (or signs in to an
existing one), then the business with its first branch, through the platform's
`POST /api/v1/account/organizations` (`../docs/integration.md`, "A new business
from your own app"). The restaurant's free trial starts with it when the
restaurant application offers one, and the app opens in the new workspace.

## Connecting it

The restaurant API accepts the tokens of one public client:
`PLATFORM_MOBILE_CLIENT_ID` in `restaurant-app/.env`. Register it with the
desktop's redirect, then put the same id in this app's `.env` as
`VITE_CLIENT_ID`:

```bash
cd ../central-app
php artisan platform:local-product restaurant-app --url=http://127.0.0.1:8700 \
  --mobile-redirect=http://127.0.0.1:48218/oauth
```
