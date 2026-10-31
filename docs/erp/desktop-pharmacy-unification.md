# Desktop pharmacy: one app, two modes, trial and licence

Assessment and plan for merging `pharmacy-electron-single-app` (offline) and
`pharmacy-electron-app` (online) into one Electron app, with a 90-day trial
and activation codes bound to a device and a branch. Nothing is built yet:
the decisions at the end come first.

Written 2026-10-30 from the code as it stands (both apps clean at `9f5f730`).

**Status:** decisions taken as recommended (2026-10-30). Phase 1 is built in
`pharmacy-electron-app` 1.1 (see its README); phases 2 onwards are not started.
`pharmacy-electron-single-app` has since been removed (it is in git history);
its 1.0.0 installer is kept, untracked, in `pharmacy-electron-app/dist/legacy-offline-1.0.0`.

## 1. What exists

Two apps, same stack: Electron 45, electron-vite, React 19, Tailwind 4,
zustand, TypeScript. No tests in either.

| | `pharmacy-electron-single-app` (Miles Pharmacy Offline) | `pharmacy-electron-app` (Miles Pharmacy) |
|-|-|-|
| appId / userData | `com.milesoftwares.pharmacy.single`, `%APPDATA%\Miles Pharmacy Offline` | `com.milesoftwares.pharmacy.desktop`, `%APPDATA%\Miles Pharmacy` |
| Where the pharmacy lives | `pharmacy.db`, SQLite via `node:sqlite` in the main process | The workspace's pharmacy-app (or Retail) over HTTPS |
| Business logic | Main process: `src/main/db/*` (counter, catalog, expenses, pages, session, settings) | Server; the renderer calls `lib/api/live.ts` (or `mock.ts`) |
| Bridge | `window.desktop.call(name, …args)` → allow-list in `src/main/handlers.ts` | `window.desktop.api(request)` → `src/main/http.ts` adds the token |
| Sign-in | Local users, scrypt hashes, three roles, kept in main memory | Platform OAuth + PKCE (`src/main/auth.ts`), tokens in `tokens.bin` via `safeStorage` |
| Branches | One, `main`, named after the pharmacy (`settings.ts → branch()`) | Many; chosen after sign-in, sent as `X-Branch-Id` |
| No connection | Not needed | Outbox for sales and cash moves, cached counter/till/catalog, sync loop (`renderer/src/offline/*`) |
| Back office | Native screens over `getPage`/`act` answered by `src/main/db/pages.ts` | Same `getPage`/`act`, answered by the workspace (`v1/app/<path>`); generic screen for pages without a native one |
| Backups | Daily `VACUUM INTO`, 14 kept, export, restore (`src/main/backup.ts`) | n/a (the workspace) |
| Licence | None. README: "A licence key: the app does not check one." | None. The workspace subscription is the licence |

Both renderers share one contract: the counter screens call a `PosApi` of
the same shape, and the back office calls `getPage(path)` / `act(method,
path, data)` returning `{ component, props, url }` / `{ message, redirect }`.
That shared contract is what makes a single UI over two back ends cheap.

**The precedent is already in the repo.** `pharmacy-mobile-app` (uncommitted
work) did this exact merge for the phone:

- `src/lib/mode.ts`: `'online' | 'local'`, chosen on first opening, saved on
  the device, changed from the sign-in screen. Each mode keeps its own data.
- `src/lib/api/index.ts`: `api` is a Proxy that sends each call to
  `localApi` or `onlineApi` by the current mode.
- `src/lib/pages.ts`: `getPage`/`act` route the same way.
- `src/local/*`: a port of the desktop offline app's database with the same
  tables, so a backup from one opens in the other.

The desktop should follow the same shape so the two stay alike.

Server side: no licence, device or activation code anywhere. central-app has
a 60-day *workspace* trial (`config/platform.php → trial_days`, the
Entitlements module) and serves both installers from `/downloads`
(`platform.downloads.apps`, the offline one says "A one-time licence: talk
to us").

## 2. Problems found

1. **Two codebases with diverging copies.** 25 renderer files exist in
   both. Several have drifted a lot: `shell.tsx` (225 differing lines),
   `login.tsx` (360), `sales.tsx` (366), `stock.tsx` (221), `navigation.ts`
   (153). The types also split: the offline app moved them to
   `src/shared/types.ts` (the main process uses them), the online app keeps
   them in `renderer/src/lib/types.ts`. Merging these is most of the work.
2. **Licensing is not enforced anywhere.** The offline installer is a free
   download that runs forever.
3. **Two installs, two data folders.** Merging changes at least one app's
   userData path. Without a migration, a customer's `pharmacy.db` looks
   lost after the update.
4. **"Hybrid" means two different things** (see decision 1). The online app
   is server-first with an offline outbox. It is not a local database that
   syncs. Nothing syncs a local SQLite pharmacy to a server today, and the
   local schema (single branch) differs from pharmacy-app's.
5. **Client-side checks can be bypassed.** Anything enforced only in the app
   can be patched out of the asar. The licence check can raise the cost of
   bypassing it. It cannot make bypassing impossible (see section 6).
6. **Smaller items:** the offline main window has `sandbox: false` (needed
   for the preload's `require`, but worth revisiting), there's no CSP check
   in either `index.html`, and installers aren't code-signed.

## 3. What can be reused as it is

- **Base: `pharmacy-electron-single-app`.** It owns the hard part: the
  database, migrations (`PRAGMA user_version`), business rules in the main
  process, backups, and more native screens (prescriptions, expenses,
  receipts, reports, settings, users, batches).
- **From the online app, moved in unchanged or nearly:** `main/auth.ts`,
  `main/http.ts`, `main/config.ts`, `renderer/src/offline/*`,
  `lib/api/live.ts`, `lib/api/mock.ts`, `lib/api/platform.ts`, the
  `branch`, `create-business` and `sync` pages, and `screens/generic.tsx`.
- `main/store.ts`, `ui.tsx`, `storage.ts` and `main.tsx` are identical in both.
- The mode switch and routing proxies from `pharmacy-mobile-app/src/lib`.
- The handler allow-list (`handlers.ts → handle`) is the natural place to
  enforce the licence: every local call already passes through it.
- The `settings` key/value table holds the installation and licence records
  with no new table needed (a migration adds one only if needed for history).

## 4. Changes needed

### Unification

- `src/renderer/src/lib/mode.ts` (`'offline' | 'hybrid'`), kept in the
  main process's store, not the renderer, so the licence gate can read it.
- `lib/api/index.ts`: Proxy over `localApi` (today's `call`) and
  `onlineApi` (live, or mock with `VITE_API_MODE=mock`). The `PosApi`
  interface becomes the union both satisfy, as on mobile (`OnlineApi` vs
  local). `moveCash`'s extra `categoryId` and `expenseCategories` are local
  only.
- `lib/pages.ts`: route `getPage`/`act` by mode.
- `store/auth.ts`: one store with `mode`, statuses `chooseMode | setUp |
  signedOut | needsBranch | locked | ready`. This file needs a careful merge.
- First screen: choose the mode, with the choice explained. Afterwards it
  changes only from the sign-in screen, as on mobile.
- Shell and navigation: one shell. Its menu comes from the mode
  (local menu vs the workspace's), the Sync page only in hybrid mode, and
  the licence status in the top bar.
- Disk keys namespaced by mode (`local.cart`, `online.outbox`…) so the two
  never mix. The online app already namespaces its keys by product.
- `electron.vite.config.ts`: keep `node:sqlite` external, add the online
  app's env typing.
- The main process opens the database only in offline mode (or lazily on
  the first local call), so a hybrid-only install never creates
  `pharmacy.db`.

### Licence and trial (main process)

New `src/main/licence/`:

- `device.ts`: a stable device id. Windows `MachineGuid`
  (`reg query HKLM\SOFTWARE\Microsoft\Cryptography`), macOS
  `IOPlatformUUID`, Linux `/etc/machine-id`. It's hashed with an app salt
  and shown as a short **device code** (`7KQ2-M9XD-4FTA`) that a customer
  can read out over the phone. No native module is needed.
- `installation.ts`: an installation id (UUID) and **branch id**, made at
  set-up and stored in the database. The licence binds to this branch.
- `trial.ts`: the trial starts at the first run (or at set-up) and lasts 90
  days. The start is written in two places, the database and a
  `safeStorage`-encrypted file in userData, and the earlier one wins.
  Deleting the database therefore doesn't restart the trial. A
  last-seen-time high-water mark spots the clock being turned back, with a
  day of tolerance. Beyond that, the app asks the user to correct the clock
  and refuses to sell.
- `codes.ts`: verifying an activation code. The code is an **Ed25519-signed
  licence**: `{ licence id, type, device hash, branch id, pharmacy name,
  issued, expires|null, max devices, max branches }`. It is checked with a
  public key built into the app (`node:crypto` supports Ed25519). It works
  with no internet, which offline customers need. Encoded as Base32 groups
  it's about 150 characters, so customers paste it. They don't type it.
- `state.ts`: one function, `licenceState()`, returns `trial (days left) |
  licensed | expired | invalid (device/branch mismatch) | clock`.
- **Enforcement in `handlers.ts`**, not the window. Each handler is marked
  `read` or `write`. When the state isn't `trial`/`licensed`, write calls
  answer `403 licence_required`, and reading, reports, backups and export
  keep working. **Data is never deleted or encrypted on expiry.** Activating
  unlocks everything at once.
- IPC: `licence.state`, `licence.activate(code)`, `licence.deviceCode`.
  None returns secrets.
- Renderer: a licence page (state, device code, paste a code, contact
  details), and a banner when 14 days or fewer remain or the trial has
  expired. A `locked` status in the auth store sends people there.

### Hybrid licensing

In hybrid mode the workspace subscription is already the licence: the
platform's entitlements say whether the workspace has Pharmacy. My
recommendation is no activation code in hybrid mode. The app trusts the
workspace and shows its trial/subscription state.

### Issuer (central-app)

A small `Licences` module:

- An admin page that issues a licence for a device code + branch and
  produces the signed code.
- A list, with revocation and transfer to a new device.
- An optional `POST /api/v1/licences/activate` that swaps a short key
  (`MILES-XXXX-XXXX-XXXX`) plus a device code for the signed code. This lets
  customers with internet activate without staff.
- The private key lives only in central-app's environment
  (`PLATFORM_LICENCE_PRIVATE_KEY`), never in git.

The desktop app works with or without this endpoint.

## 5. Database migrations

- **Migration 4** in `schema.ts`: settings rows only (`installation`,
  `licence`, `trial`). No changes to business tables, so a backup from today
  still restores, and stays compatible with the mobile local schema. The
  mobile app needs the same migration number for backups to stay
  interchangeable.
- **Data folder:** the unified app keeps the online app's identity ("Miles
  Pharmacy", `…pharmacy.desktop`). On first run, if
  `%APPDATA%\Miles Pharmacy Offline\pharmacy.db` exists and the new folder
  has none, the app copies the database and `backups/` across. It never
  moves or deletes them, and it starts in offline mode. Existing online
  installs keep their folder and open in hybrid mode.
- **Restoring a backup onto another PC** brings the branch id but not the
  device. The licence then answers `invalid (device)` and asks for a
  transfer. The data stays readable.
- **Existing offline customers** have no licence record. Policy needed
  (decision 4): either they get a fresh 90 days from the update, or they're
  issued a licence.

## 6. Security

- Licence checks live in the main process. The renderer only displays the
  state.
- The signing key stays private. Only the public key ships, so codes can't
  be forged without patching the app.
- Anti-tamper: Electron fuses (`EnableEmbeddedAsarIntegrityValidation`,
  `OnlyLoadAppFromAsar`, `RunAsNode` off, `EnableNodeCliInspectArguments`
  off) and Windows code signing. Together these stop casual patching. A
  determined attacker can still patch any client-side app, so this deters
  rather than prevents. Hybrid mode doesn't have this weakness because the
  server decides.
- Trial storage: encrypted with `safeStorage` and cross-checked against the
  database, with clock rollback detection. Reinstalling Windows resets a
  trial. That's acceptable.
- Device id: hashed before display or sending. The raw OS id never leaves
  the PC.
- IPC: keep the named allow-list, and add the read/write marking. Review
  `sandbox: false` and add a CSP to `index.html`.
- Tokens: unchanged (`safeStorage`, main process only). Local passwords stay
  scrypt.

## 7. Breaking changes and risks

- **userData path** for current offline installs (handled by the copy above).
- **Two Start-menu entries** until the old offline app is uninstalled. NSIS
  keeps userData on uninstall by default, so the copy still works after.
- **Downloads page**: two entries become one (`platform.downloads.apps`).
- **The renderer merge** (shell, login, sales, stock, navigation, types) is
  the riskiest part. It needs both modes clicked through by hand, because
  neither app has tests. I'd add Vitest for the main-process licence and
  trial logic at least: dates, clock rollback, signatures, device mismatch.
- **Mock/demo mode**: keep it in hybrid mode only, as now.

## 8. Phases

| Phase | What | Shippable alone |
|-|-|-|
| 0 | Decisions below | n/a |
| 1 | One codebase: offline app as base, online code moved in behind `mode`, mode chooser, per-mode disk keys, data-folder copy, single installer "Miles Pharmacy" 1.1. No licence yet | Yes |
| 2 | Licence core: device code, installation and branch ids, 90-day trial, `licenceState`, read/write gate in `handlers.ts`, licence page and banner, signed-code verification with a test key, Vitest for all of it | Yes (codes made by a script with the key) |
| 3 | central-app Licences module: issue, list, revoke, transfer; keypair in env; the offline download becomes "90-day trial" | Yes |
| 4 | Online activation with a short key, a periodic check in hybrid mode, revocation honoured | Yes |
| 5 | Hardening: fuses, code signing, auto-update | Yes |
| 6 (only if wanted) | Local-first hybrid: the SQLite pharmacy syncing to a workspace. Weeks of work, needs sync endpoints in pharmacy-app | n/a |

Licence types, carried in the code from phase 2 so they can grow later:
`single` (1 device, 1 branch) at first. `multi_device` (n devices, 1 branch)
needs either a shared database on the LAN or the workspace.
`multi_branch` is effectively hybrid mode with a workspace.

## Decisions needed

1. **What "Hybrid" means.** (a) The workspace counter as today, with the
   outbox (recommended: it exists and works), or (b) a local database that
   syncs both ways with a server (phase 6, large).
2. **How codes reach offline customers.** A signed code they paste
   (recommended, works with no internet), plus the optional short key
   online.
3. **Trial length vs the platform.** 90 days on the desktop vs 60 for
   workspaces. Keep them different?
4. **Existing offline installs.** Fresh 90-day trial from the update, or
   issue them licences?
5. **The app's name and identity.** Keep "Miles Pharmacy" /
   `com.milesoftwares.pharmacy.desktop` (recommended) and retire "Miles
   Pharmacy Offline"?
6. **Retail.** The online app also sells for shops. Should the offline mode
   stay pharmacy-only?
