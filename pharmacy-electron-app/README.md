# Miles Pharmacy (desktop)

The counter of the Miles Softwares pharmacy (`../pharmacy-app`) on a Windows,
macOS or Linux PC, built to keep selling when the internet stops. Electron +
React + Tailwind (the shared theme from `../packages/ui`). It talks to the same
Pharmacy API as the mobile app (`../pharmacy-mobile-app`).

## Run

```bash
npm install
cp .env.example .env     # VITE_API_MODE=mock runs the demo pharmacy
npm run dev              # the app, with hot reload
npm run typecheck
npm run dist:win         # installer in dist/ (dist for this OS)
```

If `npm run dev` says Electron failed to install, fetch its binary:
`node node_modules/electron/install.js`.

Keys: F1 Sell, F2 Sales, F3 Shift, F4 Sync, `/` to search. A barcode scanner
types into the search and presses Enter: the medicine goes into the cart.

## Working offline

| | Online | Offline |
|---|---|---|
| Find medicines | the catalog on this PC, topped up by the pharmacy's search | the catalog on this PC |
| Sell | sent to the pharmacy | kept in the outbox, sent when the connection is back |
| Cash in and out | sent | kept in the outbox |
| Shift, counter rules, sales, receipts | from the pharmacy, saved here | as last saved here |
| Open or close a shift | yes | no (the shift is the pharmacy's) |
| Sign in | yes | someone already signed in keeps working |

- **The outbox** (`src/renderer/src/offline/outbox.ts`): each sale and cash
  movement keeps its submission id, so one that reached the pharmacy just as
  the connection dropped is never recorded twice. Entries are sent oldest
  first, only by the person at the branch that made them. If the pharmacy
  refuses one (stock gone, shift closed) it waits on the Sync page: try again,
  put the sale back in the cart, or discard it. A shift cannot be closed while
  anything waits.
- **Sync** (`offline/sync.ts`) runs when the app opens, when the connection
  comes back, every 20 seconds while anything waits, and from Sync now: it
  sends the outbox first, then refreshes the shift, the counter and the
  catalog (every 10 minutes, or after sending sales).
- **Connectivity** (`offline/connectivity.ts`): every call reports whether it
  got an answer. Offline, the app checks again every 15 seconds.
- **Stock offline**: the catalog shows stock as of its last sync, minus what
  waits in the outbox.
- **On disk**: everything is under the app's user-data folder (`data/*.json`,
  written atomically). The tokens are in `tokens.bin`, encrypted by the OS.
- **Demo**: with `VITE_API_MODE=mock`, "Simulate offline" in the top bar cuts
  the demo pharmacy off, so you can try the outbox. The demo pharmacy resets
  when the app restarts.

## Live mode and sign-in

The main process (`src/main`) holds the tokens and makes every API call, so the
page never sees a token and no call is held up by CORS. Every way of signing
in is the platform's authorization code flow with PKCE (`src/main/auth.ts`):

- **Email and password** on the app's own screen. The main process runs the
  platform's web sign-in (Fortify `POST /login`, then `/two-factor-challenge`
  when the account has two-factor) in a cookie session of its own, fresh
  each time. It then asks `/oauth/authorize` again and reads the code from
  the redirect. Wrong details, suspended accounts and the five-attempts-a-minute
  limit answer as on the web. An account that must do something on the web
  first (choose a new password, say) is told to sign in with the browser.
- **Continue with Google** opens the system browser on
  `/oauth/authorize?idp_hint=google&prompt=login`, which goes straight to
  Google's account chooser (central-app `SignInWithHintedProvider`). Google
  refuses sign-in inside app windows, so it is never shown in this one.
- **Sign in with the browser** (passkeys, or anything else) opens the
  platform's sign-in page.

The browser paths come back to `http://127.0.0.1:48217/oauth`, a loopback
address the app listens on for that one reply.

The pharmacy API accepts the access tokens of one public client, the
pharmacy's mobile client (`PLATFORM_MOBILE_CLIENT_ID` in `pharmacy-app/.env`),
so the desktop app signs in as that client: its redirect URIs include both
the mobile app's and the desktop's.

### Against the local pharmacy-app

With central-app and pharmacy-app running behind Apache
(`ops/local-dev`), `.env`:

```
VITE_API_MODE=live
VITE_AUTH_URL=http://auth.milesoftwares.localhost
VITE_CLIENT_ID=<PLATFORM_MOBILE_CLIENT_ID from pharmacy-app/.env>
VITE_OAUTH_PORT=48217
VITE_WORKSPACE_URL=http://{workspace}.milesoftwares.localhost
VITE_PRODUCT_PATH=pharmacy
```

Add the desktop's redirect to that client once (from central-app; the
mobile app's URI stays):

```bash
php artisan tinker --execute='$c=App\Modules\Identity\Models\OAuthClient::find("<client id>"); $u=json_decode($c->getRawOriginal("redirect_uris"),true); $u[]="http://127.0.0.1:48217/oauth"; $c->forceFill(["redirect_uris"=>array_values(array_unique($u))])->save();'
```

Then `npm run dev`, workspace `demo-pharmacy` (or any workspace with the
pharmacy), and sign in in the browser as a member with a pharmacy role.
After a change to `.env`, restart `npm run dev`: the values are baked in.

In production the URI is added in admin (Integrations → the pharmacy → its
public client).

### A shop (Retail)

The same app sells for a shop: the deployment serves Retail at
`{workspace}/retail`, with its own API (`retail/api/v1`) and its own public
client. With `VITE_RETAIL_CLIENT_ID` set, the sign-in screen offers
Pharmacy or Shop; the choice picks the path and the client, and the tokens
remember it. Queued sales, held carts, saved pages and the catalog are kept
per product, so a branch that is both never mixes them. The shop's menu,
wording and till follow: promotions shown, a serial number asked for each
unit of a product that tracks them, no expiry or prescriptions. Locally,
register the shop's client with the desktop's redirect (from central-app),
then copy `PLATFORM_RETAIL_MOBILE_CLIENT_ID` from `pharmacy-app/.env`:

```bash
php artisan platform:local-product pharmacy-app --catalog=platform/retail.json --prefix=retail --mobile-redirect=http://127.0.0.1:48217/oauth
```

The demo (mock) mode is a pharmacy only.

| Variable | |
|---|---|
| `VITE_API_MODE` | `mock` or `live` |
| `VITE_AUTH_URL` | The platform's sign-in host |
| `VITE_CLIENT_ID` | The desktop client's id (the pharmacy's mobile client) |
| `VITE_OAUTH_PORT` | The loopback port of the redirect URI (48217) |
| `VITE_WORKSPACE_URL` | `https://{workspace}.milesoftwares.com` |
| `VITE_PRODUCT_PATH` | `pharmacy` |
| `VITE_RETAIL_CLIENT_ID` | The shop's mobile client; empty: pharmacy only |
| `VITE_RETAIL_PATH` | `retail` |

## The back office

Everything else the web does, the desktop does through the same pages: the
pharmacy mirrors each web page at `{workspace}/pharmacy/api/v1/app/<web path>`
(pharmacy-app `AppSupportAppPages`, middleware `ServeInertiaAsJson`).
Same controllers and permissions as the web. A GET answers the page's
component and props. An action answers its message and the page to go to
next (`redirect: null` means stay and reload). Validation comes back per field.

- The menu (`lib/navigation.ts`) follows the web's and shows what the member
  may use in this branch, from the props every page carries.
- `screens/registry.tsx` maps a page's component to its native screen. Pages
  without one yet are shown by `screens/generic.tsx` (read-only tables, plus
  "Open on the web").
- Native so far: dashboard, stock (list, and batches with add, adjust, hold,
  release, dispose and move), medicines (list and form), suppliers.
- Pages are saved on this PC once opened and can be read offline. Changes to
  them need the connection: only sales and cash movements wait in the outbox.
- In the demo (mock mode), only the counter is shown.
- `tests/Feature/AppPagesTest.php` in pharmacy-app pins the props the native
  screens read.

## Layout

```
src/main/          window, IPC, sign-in (auth.ts), API calls (http.ts), data on disk (store.ts), printing
src/preload/       window.desktop, the bridge (types in src/shared/ipc.ts)
src/renderer/src/
  lib/api/         PosApi: live and mock (as in the mobile app)
  offline/         connectivity, cache, catalog, outbox, sync
  store/           session, cart (held carts), ui
  pages/           login, branch, sell, sales, shift, sync (the counter, offline)
  screens/         back-office pages (registry.tsx), via lib/pages.ts and hooks/use-page.ts
  components/      shell, cart, checkout, ui kit
```

## Not yet

- Native screens for prescriptions, orders, shifts, customers, counter
  settings, locations, transfers, disposals, the controlled register,
  purchasing and reports. Until then they show as generic pages.
- A full catalog download: the API returns at most 50 items a search, so the
  catalog is built from the in-stock list plus a search per letter and digit.
  A `GET items?updated_since=` endpoint would make this complete and cheaper.
- Silent printing to a chosen receipt printer (prints use the system dialog).
- Auto-update and code signing for the installer.
