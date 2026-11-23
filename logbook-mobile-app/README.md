# Tatook Logbook (`logbook-mobile-app`)

**Operational records.** *Every shift, on the record.*

The Android app that operators use to write Tatook Logbook entries, offline, and that
reviewers use to review them (ADR-0025 §8). Expo SDK 57, expo-router, NativeWind, zustand.
Package `com.tatook.logbook`.

Log sheets (templates), logbooks, access, reports and the audit trail are managed on the web
(`logbook-app`). The phone only writes and reviews entries.

## Screens

- **Home:** returned and refused entries, drafts, entries waiting to send, entries awaiting
  review, connection state and last sync.
- **Entries:** the person's own entries on this phone. Filters: needs you, drafts, sending,
  in review, done, all. Search by reference or sheet.
- **New:** the log sheets the person writes on, grouped by logbook. Shown only to roles with
  `logbook.entries.create`.
- **Reviews:** entries waiting for this reviewer. Shown only with `logbook.entries.review`.
  Online only.
- **Sync:** Sync now, Retry failed, Update sheets, the counts and what was not sent.
- **Entry (`entry/[id]`):**
  - a draft, or an entry returned for correction, opens as its form and is saved on the
    phone as it is typed;
  - **Finish and send** checks it with the server's rules and queues it;
  - anything else opens read-only, with its review.
- **Review (`review/[id]`):**
  - the answers, photos and earlier decisions;
  - **Mark reviewed**, or **Return** with a comment.
  - The decision carries the revision read. If the author changed the entry meanwhile, the
    server refuses it and the screen reloads the entry.

## How it works

- **Sign-in:** through Tatook Central (OIDC with PKCE, or Google through Central). Tokens are
  kept in the Android Keystore (expo-secure-store). The app then opens a session at
  `{workspace}/logbook/api/session` and reads `api/v1/me` (`can.write`, `can.review`).
- **Storage:** one SQLCipher database (`logbook.db`, expo-sqlite with `useSQLCipher`).
  - Its key is 32 random bytes held in the secure store.
  - Every row is scoped `workspace|user`, so another person on the same phone sees nothing.
  - Unreadable rows are moved to a `quarantine` table, never deleted.
- **Log sheets:** `GET api/v1/kit` lists the sheets the person can write on, their published
  version, the workspace members (for person fields) and the file limits.
  - A published version never changes, so each one is downloaded once
    (`GET api/v1/sheet-versions/{id}`).
  - A draft stays on the version it was started on.
- **Field rules:** `src/logbook/fields.ts` ports the server's `App\Logbook\Fields`, with the
  same messages. The phone checks an entry before queueing it; the server checks it again and
  decides.
- **Files:**
  - Photos from the camera or gallery are re-encoded as JPEG, which drops EXIF, and resized
    to fit the limit.
  - Documents (PDF, Word, Excel, CSV, text) are copied as they are.
  - Files are kept in the app's private directory until the server has them, then removed.
- **Sync (`src/logbook/sync.ts`):** the same steps for every entry, and every step is safe to
  repeat:
  1. `POST entries` with the phone's UUIDv7 as the idempotency key; once the entry exists,
     `PUT entries/{id}` from the revision the phone holds;
  2. files removed in a correction are deleted, then new files are uploaded one by one (the
     server deduplicates them by SHA-256);
  3. `POST entries/{id}/submit` with the revision this creates.
- **Failures:**
  - A request with no reply is never treated as done.
  - When offline, on a timeout, a 429 or a 5xx, the entry is retried by itself, with backoff
    from 30 s to 30 min plus jitter.
  - A 401 waits for a new sign-in.
  - A 403, 404, 409, 410 or 422 needs a person ("Needs you"), with the server's reason and its
    field errors.
- **Corrections:** the app pulls `GET api/v1/entries?since=` on open, when it comes back to the
  front, when the network returns, and on pull-down.
  - An entry returned for correction becomes "Needs you", with the reviewer's comment.
  - Work not yet sent from the phone is never overwritten.
  - Synced entries leave the phone 90 days after their last change; the server keeps them.
- **Sending vs review:** the two are kept apart. Sending shows whether the server has the entry
  (Draft, Waiting to send, Sending, Sent, Not sent yet, Needs you). Review shows what the
  reviewer decided (Awaiting review, Returned, Reviewed, Recorded). Reaching the server is
  never shown as reviewed.
- **Signing out** never deletes unsent entries. They stay encrypted and are sent at the next
  sign-in. If anything is unsent, signing out asks you to type `SIGN OUT`.

## Develop

```bash
npm install
cp .env.example .env      # fill in EXPO_PUBLIC_CLIENT_ID (see below)
npm run typecheck
npm run lint
npm test                  # field rules and the sync protocol against a fake server
npm run usb               # phone over USB: adb reverse, then expo start
```

The app uses native modules (SQLCipher, secure store, Google sign-in), so it needs a development
build (`npx expo run:android` or `eas build --profile development`), not Expo Go.

The mobile client is registered on Central with
`php artisan platform:local-product logbook-app --url=http://127.0.0.1:9600 --mobile-redirect=com.tatook.logbook://oauth`
(production: the same with the production URL). Its client ID goes in
`EXPO_PUBLIC_CLIENT_ID` here and in `PLATFORM_MOBILE_CLIENT_ID` in `logbook-app/.env`.

## Icon

`php -d memory_limit=2G scripts/cut-icon.php assets/images/logo-source.png assets/images ../logbook-app/public/logbook/images` cuts every variant from the official logo (a blue spiral notebook with green ticks and a pen, on a white rounded tile): icon, adaptive foreground, background and monochrome, splash, logo, favicon, and the web app's sidebar logo and tab icons.

## Android releases

Google Play internal testing uses the `production` profile. EAS keeps the Android `versionCode` remotely (`cli.appVersionSource` is `remote`) and increments it on each production build. That build is an Android App Bundle. Development and preview builds do not increment the version code and are not for Play.

Version code 2 is already uploaded, and the internal track already has a completed 1.0.0 release of that bundle. Do not submit that AAB again. Play rejects a second upload of the same version code.

```bash
npx eas-cli build:version:get -p android
npx eas-cli build -p android --profile production --auto-submit
npx eas-cli submit:list -p android
```

`--auto-submit` sends the new AAB to the internal track. A finished submission means Play accepted the upload and the internal release was marked completed. It does not mean the app is on the production track or that testers have installed it. Confirm the tester list in Play Console under Internal testing.

If Play reports that the version code was already uploaded, leave that AAB alone. Check the internal track, then run the production build again so the next version code is used.

## Not done yet

- Not run on a device. The EAS project is already set (`extra.eas.projectId` in `app.json`); do not run `eas init` again.
- Google sign-in on Android needs an Android OAuth client for `com.tatook.logbook`, with the
  SHA-1 of each signing key.
- Not deployed: the API ships with `logbook-app`.
