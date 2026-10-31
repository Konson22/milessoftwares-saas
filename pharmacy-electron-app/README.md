# Miles Pharmacy (desktop)

The Miles Softwares pharmacy on a Windows, macOS or Linux PC. Electron +
React + Tailwind (the shared theme from `../packages/ui`). One app, two ways
of running the pharmacy, chosen on the first opening (`src/main/mode.ts`):

| | Offline: on this PC only | Hybrid: with a Miles workspace |
|-|-|-|
| The pharmacy | `pharmacy.db` (SQLite) on this PC, no internet, no account. One PC, one branch | The workspace's pharmacy (`../pharmacy-app`) or shop: branches, the web back office, every device in step |
| Signing in | A username and password; the first manager is made at set-up and adds staff under Users | The platform (below) |
| Calls | `window.desktop.call` → `src/main/handlers.ts` → `src/main/db` | `window.desktop.api` → `src/main/http.ts` → `{workspace}/pharmacy/api` |
| Back office | Its own pages and screens (`src/main/db/pages.ts`, `screens/local`) | The web's pages, read from the workspace (`screens/`) |
| No connection | Not needed | Sales and cash moves wait in the outbox and sync (`src/renderer/src/offline`) |
| Keeping it safe | Daily backups, to a USB stick, restore | The workspace |

The counter's screens (sell, sales, shift, cart, checkout) are the same for
both: `api` (`lib/api/index.ts`) and `getPage`/`act` (`lib/pages.ts`) send
each call to the chosen side, as in the mobile app (`../pharmacy-mobile-app`).
The choice is kept by the main process, which answers `call` only in offline
mode; it changes from the sign-in screen, and each mode keeps its own data.
Installs from before the choice existed open where they were.

Until 1.1 the offline side was a separate app, Miles Pharmacy Offline
(`pharmacy-electron-single-app`, since removed; it is in git history). On the
first opening after the update, its pharmacy is copied from `%APPDATA%\Miles Pharmacy Offline` (database,
backups, the cart in progress) and the app opens offline. The old folder is
left as it was (`src/main/legacy.ts`).

## Run

```bash
npm install
cp .env.example .env     # hybrid: VITE_API_MODE=mock runs the demo pharmacy
npm run dev              # the app, with hot reload
npm run typecheck
npm run dist:win         # installer in dist/ (dist for this OS)
```

If `npm run dev` says Electron failed to install, fetch its binary:
`node node_modules/electron/install.js`.

Keys: F1 Sell, F2 Sales, F3 Shift, F4 Sync (hybrid) or Prescriptions
(offline), `/` to search. A barcode scanner types into the search and
presses Enter: the medicine goes into the cart.

## Offline: the pharmacy on this PC

On set-up the app asks for the pharmacy (its name, address and currency) and
its first manager, and can add sample medicines with stock to try it.

| | |
|---|---|
| Counter | sell (FEFO: soonest-to-expire batch first), packs, discounts, held carts, receipts, void and return |
| Shifts | open with a float, cash in and out, close with a count (blind for cashiers if set); expenses paid from the till |
| Prescriptions | record, verify (pharmacist), reject, dispense in part or whole; prescription-only medicines sell only this way |
| Inventory, batches | stock per medicine and every batch with its cost, selling price and margin; add, adjust, dispose, hold back, release, reprice |
| Receive stock | deliveries by the unit or the box, in the pharmacy's currency or the second one |
| Pricing | each batch sells at its own price, soonest to expire first (`src/shared/pricing.ts`, used by both the cart and the sale) |
| Expenses | by category: operating, recurring (due and overdue) or one-off; paid from the safe, the bank, mobile money or the till |
| Reports | net sales, cost, gross profit, expenses, net profit, by day, payment and person, best sellers, shifts, stock value, low and expiring |
| Users, settings | manager, pharmacist or cashier; the second currency and its rate, the receipt, payment methods, discount limit, shifts |
| Backups | daily automatic copy (last 14 kept), back up now, save to a USB stick, restore |

Roles: a cashier sells, records prescriptions and sees stock. A pharmacist
also discounts, verifies prescriptions, and manages medicines, stock,
suppliers and deliveries. A manager can do everything, including voids,
returns, expenses, reports, users, settings and backups.

- **The database** is `pharmacy.db` (WAL) in the app's user-data folder
  (`%APPDATA%\Miles Pharmacy`), opened by the main process only, through
  `node:sqlite` (built into Electron: no native module). It is opened on the
  first call, so a PC that works with a workspace never makes one.
- **Schema** changes are migrations in `src/main/db/schema.ts`, applied by
  `PRAGMA user_version`. The app refuses a database made by a newer version,
  and says so. The mobile app's offline side uses the same tables, so a
  backup from one opens in the other: keep their migrations in step.
- **Backups** are under `backups/`, each a whole database made by
  `VACUUM INTO`. Restoring first keeps what is there as a "before a restore"
  copy, then signs everyone out.
- **Amounts** are integers in the currency's minor unit; the number of
  decimals is set at setup.

## Hybrid: working offline

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

## Hybrid: sign-in, live and demo

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

## Hybrid: the back office

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
src/main/
  index.ts         window, IPC, printing; daily backups (offline)
  mode.ts          offline or hybrid, kept in userData/mode.json
  legacy.ts        the copy from Miles Pharmacy Offline; the mode of installs from before the choice
  handlers.ts      offline: what the window may call, by name (refused in hybrid)
  db/              offline: the database (schema, counter, catalog, expenses, pages, session, settings)
  backup.ts        offline: backups
  auth.ts, http.ts hybrid: sign-in and API calls
  store.ts         small things the window keeps (userData/data)
src/preload/       window.desktop, the bridge (types in src/shared/ipc.ts)
src/shared/        types (both sides), pricing, currencies
src/renderer/src/
  lib/mode.ts      the mode, for calls and pages
  lib/api/         api: localApi (offline) or onlineApi (live and mock), by mode
  offline/         hybrid: connectivity, cache, catalog, outbox, sync (they stand aside offline)
  store/           session (auth.ts, both modes), cart (held carts), ui
  pages/           choose-mode, login, branch, sell, sales, shift, sync; local/: sign-in, set-up, prescriptions
  screens/         back-office pages: the workspace's (registry.tsx), local/ this PC's
  components/      shell, cart, checkout, ui kit
```

## Not yet

- Trial and licence activation for offline mode (docs/erp/desktop-pharmacy-unification.md, phase 2).
- Offline: purchase orders, supplier returns, the controlled-drugs register and stock counts.
- Hybrid: native screens for prescriptions, orders, shifts, customers, counter
  settings, locations, transfers, disposals, the controlled register,
  purchasing and reports. Until then they show as generic pages.
- A full catalog download: the API returns at most 50 items a search, so the
  catalog is built from the in-stock list plus a search per letter and digit.
  A `GET items?updated_since=` endpoint would make this complete and cheaper.
- Silent printing to a chosen receipt printer (prints use the system dialog).
- Auto-update and code signing for the installer.
