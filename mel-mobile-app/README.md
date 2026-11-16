# Tatook M&E field app (`mel-mobile-app`)

The Android app enumerators use to fill in M&E Data Collection forms offline
(ADR-0012 §8–9, ADR-0019 §22). Expo SDK 57, expo-router.

- **Sign-in:** Tatook Central (OIDC with PKCE, or Google through Central). Tokens live in
  the Android Keystore (expo-secure-store). The app then opens a session at
  `{workspace}/mel/api/session` and reads `api/v1/me`.
- **Storage:** one SQLCipher database (expo-sqlite, `useSQLCipher`), keyed with 32 random
  bytes held in the secure store. Every record is scoped `workspace|user`; another person
  on the same phone sees nothing of it. Unreadable rows are moved to a `quarantine` table,
  never deleted. `android.allowBackup` is off.
- **Forms:** the assignment kit and immutable form versions are downloaded once
  (by hash) and rendered from their definition. Rules run on the phone with
  `src/collect/rules.ts`, a byte-for-byte copy of mel-app's (`npm run rules:check`), tested
  against `contracts/collect/rules-cases.json` like the server and the web.
- **Photos and GPS:** photos are re-encoded to JPEG within the workspace's size limit (drops
  EXIF) and kept in the app's private directory, never the gallery; removed once the
  server has them. GPS records accuracy.
- **Sync (`src/collect/sync.ts`):** submissions carry a UUIDv7 made on the phone, the
  server's idempotency key. A lost reply is settled by resending the same id. Photos resume
  from the server's `missing` list. Offline / timeout / 429 / 5xx retry with backoff and
  jitter (30 s → 30 min); 401 waits for sign-in; 403/404/409/410/422/426 become *Action
  required* and are never retried alone. Corrections asked by reviewers come back through
  the incremental `submissions?since=` feed and are sent against the reviewer's base revision.
- **Sign-out:** never deletes unsent records; with unsent work it needs a typed confirmation.

## Commands

```
npm test              # Jest: sync protocol (lost replies, restarts, interrupted uploads,
                      # week-long disconnection, refusals, corrections) + rules conformance
npm run typecheck
npm run rules:check   # rules.ts / definition.ts still identical to mel-app's
npx expo export --platform android   # JS bundle
```

A device build needs an EAS project (`eas init`) and `EXPO_PUBLIC_CLIENT_ID`
(the platform client registered for this app, also `PLATFORM_MOBILE_CLIENT_ID` in mel-app).

## Not yet verified

The app has **not been run on a device or emulator**. The sync protocol is tested in Jest
against a fake server; the native pieces (SQLCipher, camera, location, file system) are
typed and bundled but not exercised. The icon and splash are still the petrol app's
placeholders.
