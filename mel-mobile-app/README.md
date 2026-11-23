# Miles MDC (`mel-mobile-app`)

**Mobile Data Collection & Monitoring.** *Collect with confidence. Turn field data into action.*

The Android app enumerators use to fill in Tatook M&E Data Collection forms offline
(ADR-0012 §8–9, ADR-0019 §22). Expo SDK 57, expo-router. Package `com.tatook.mel`
(kept: the store listing and the platform client are registered under it).

## Screens

Four tabs, plus the record and the account:

- **Home:** what to do next: records to correct, the latest draft, open tasks, tasks due this
  week, connection and last sync.
- **Tasks:** one per M&E *assignment* (a form, for a programme unit, between two dates). The
  phone keeps no task list of its own. Shows the type (the form's purpose), programme, place
  (unit), due date, form version, offline state and progress (server counts by review state
  when the server sends them). Filters: all, open, due this week, needs attention; search.
- **Forms:** the assigned forms by programme (version, available offline or not), plus
  Drafts, Submitted and Needs me.
- **Sync:** Sync now, Retry failed, Update forms. Two ledgers are kept apart: **Upload**
  (ready, sending, failed, refused, received by the server) and **Review** (verification
  pending, approved, correction required, rejected). Reaching the server is never shown as
  approval.
- **Record (`fill/[id]`):** one section per page with a progress bar. Save and continue later.
  A review page lists every answer and its error before Submit. A draft stays on the form
  version it was started on. When a newer version exists, the collector may start again on it
  (nothing is copied, the old draft is kept).

## How it works

- **Sign-in:** Tatook Central (OIDC with PKCE, or Google through Central). Tokens live in
  the Android Keystore (expo-secure-store). The app then opens a session at
  `{workspace}/mel/api/session` and reads `api/v1/me`.
- **Storage:** one SQLCipher database (expo-sqlite, `useSQLCipher`), keyed with 32 random
  bytes held in the secure store. Every record is scoped `workspace|user`; another person
  on the same phone sees nothing of it. Unreadable rows are moved to a `quarantine` table,
  never deleted. `android.allowBackup` is off.
- **Forms:** the assignment kit and immutable form versions are downloaded once (by hash) and
  rendered from their definition. Rules run on the phone with `src/collect/rules.ts`, a
  byte-for-byte copy of mel-app's (`npm run rules:check`), tested against
  `contracts/collect/rules-cases.json` like the server and the web. Hidden answers stay in the
  draft while filling in; they are dropped when the record is finished, as on the server.
- **Photos and GPS:** photos are re-encoded to JPEG within the workspace's size limit (drops
  EXIF) and kept in the app's private directory, never the gallery; removed once the server
  has them. GPS records accuracy.
- **Sync (`src/collect/sync.ts`):**
  - Each submission carries a UUIDv7 made on the phone, which is the server's idempotency key.
    A lost reply is settled by resending the same id.
  - Photos resume from the server's `missing` list.
  - Offline, timeout, 429 and 5xx retry with backoff and jitter (30 s → 30 min).
  - 401 waits for sign-in.
  - 403/404/409/410/422/426 become *Action required* and are never retried on their own.
  - Corrections asked by reviewers come back through the incremental `submissions?since=` feed
    and are sent against the reviewer's base revision.
  - Timing: the app syncs when the network comes back and refreshes fully when it returns to
    the front. A one-minute timer sends only when something is due, so an idle phone makes no
    requests.
- **Sign-out:** never deletes unsent records; with unsent work it needs a typed confirmation.

## Commands

```
npm test              # Jest: sync protocol (lost replies, restarts, interrupted uploads,
                      # week-long disconnection, refusals, corrections), rules conformance,
                      # tab logic (upload vs review, tasks, pages, retry)
npm run typecheck
npm run lint
npm run rules:check   # rules.ts / definition.ts still identical to mel-app's
npx expo export --platform android   # JS bundle
php -d memory_limit=2G scripts/cut-icon.php assets/images/logo-source.png assets/images   # icon variants
```

A device build needs `EXPO_PUBLIC_CLIENT_ID`. This is the platform client registered for this
app, also set as `PLATFORM_MOBILE_CLIENT_ID` in mel-app.

## Not built (needs the server first)

- **Question types:** signature, file attachment, repeat groups, calculated fields. They need
  form schema version 2 in mel-app (Definition, Rules, the web designer and the shared rules
  cases), not only the phone.
- **Indicators:** indicator evidence from collected data (ADR-0012 §11: mappings and
  calculation runs, approved records only). This is a backend feature; the phone sends what
  it does today.
- **Beneficiaries:** M&E has no beneficiary or household register. None is invented on the
  phone.
- **Task priority:** assignments have no priority, so none is shown.
- **Project names:** the project name of a unit linked to Tatook Projects. The unit label is
  shown.

## Not yet verified

The app has **not been run on a device or emulator**. The sync protocol and the tab logic are
tested in Jest against a fake server. The native pieces (SQLCipher, camera, location, file
system, the tab bar) are typed and bundled but not exercised.
