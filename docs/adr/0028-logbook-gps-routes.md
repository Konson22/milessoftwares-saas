# ADR-0028: Miles Logbook: optional GPS, entry locations and routes recorded on the phone

Status: **Accepted (2026-11-20). Phase 1 built locally, uncommitted, not deployed. Not yet run on a device.**
Extends ADR-0025, 0026 and 0027. Same app (`logbook-app`), same phone app (`logbook-mobile-app`), same database. Phases 2 (GPS devices) and 3 (fleet management) are **Coming soon** pages only.

## 1. What existed before

- Resources (vehicles, generators…) chosen in entries, with a free-text `location` field on the resource.
- No coordinates anywhere: no browser or phone location, no route, no map. `expo-location` was not installed and the phone **blocked** both location permissions in `app.json`.
- An offline-first phone sync engine (`sync.ts`: idempotent by the phone's id, explicit states, backoff, nothing "synced" without the server's answer) and an encrypted SQLCipher store.

## 2. Decisions

| # | Topic | Decision |
|---|---|---|
| 1 | Where GPS is switched on | Per log sheet, in the template: **off** (default), **entry location**, or **route**. Stored on `log_sheet_versions.location` (json), so it is published with the template and an entry keeps the settings of the version it was written on. Published versions stay immutable. Options: `required`, `resource_required` (route only), `accuracy_m` guidance (5–1000). |
| 2 | Entry location | `log_entries.location` json `{lat, lng, accuracy_m, captured_at, received_at, source: phone\|browser}`, copied into each revision. Read only when the person presses "Take location"; never made up. A sheet with GPS off drops any coordinates sent. Required: submit refused (`location`) without a location or a route link. |
| 3 | Route model | `log_tracks` keyed by the **phone's UUIDv7**: person, logbook, sheet, optional resource and task, `source` (`phone` only, CHECK), status recording/paused/ended (forward only; ended is final), summary (distance, moving time, points, rejected, gaps, gap time, bounds, last seq). `log_track_points` and `log_track_events` share the phone's `seq`, unique `(track, seq)`: a batch sent twice is stored once. Tenant composite foreign keys throughout. |
| 4 | Distance | Recomputed by the server from the stored points (`App\Logbook\Route`): accuracy worse than 75 m, non-increasing times and jumps faster than 70 m/s left out; steps under 5 m are jitter (not counted, reference point kept). Nothing counted across a pause, an end, an interruption, location turned off or permission lost, or a silence over 5 minutes. The phone shows its own estimate with the same rules. Labelled "estimated" everywhere. |
| 5 | Gaps | Breaks other than pause/end, and silences over 5 min, are gaps with a reason (`interrupted`, `gps_off`, `permission_lost`, `no_signal`), drawn dashed in red and labelled "not the road travelled". |
| 6 | Phone recording | Started only by the person (Start trip, after a disclosure they tick). `expo-location` with a **user-initiated foreground service** and its notification for as long as it records; **while-in-use permission only**. `ACCESS_BACKGROUND_LOCATION` is explicitly blocked in `app.json` (expo-location does not require it with a foreground service). Pause and End stop the service, so nothing is read in between. 5 s / 10 m sampling, high accuracy, delivered in groups of up to 15 s. |
| 7 | Phone storage | The same SQLCipher database, rows scoped `account|workspace`: `track` (state) and `tpt` records (points and events, key padded by seq so key order is seq order). Each append writes the records and the new numbering in one exclusive transaction. Records are removed only after the server confirmed them. A global pointer names the route recording on this phone. |
| 8 | Phone sync | Reuses the entry engine's rules (`classify`, `backoff`, `TransportError`): upsert the route, then batches of up to 500 records; the server answers the highest seq it holds. "Synced" only when the server has the state and every record. 409 refusals (`resource_required`, `sheet_closed`…) need a person; 401 waits for sign-in. |
| 9 | Recovery | On app start, a route still marked recording with no running service is recorded as **interrupted** and paused; the person chooses Resume (records `recovered`) or End. Never resumed by itself. Signing out or switching workspace stops a running route (kept for its owner). On return to the app: location off → `gps_off`/`gps_on`; permission revoked → `permission_lost` + interrupted. |
| 10 | Linking | An entry may name a route (`track_id`): accepted only for the person's own route in the same logbook; otherwise the link is dropped (the entry is kept). The phone ticks the link by default for new entries on the trip's sheet. |
| 11 | Who sees routes | Your own always. Others' with the new permission `logbook.tracks.view` (sensitivity 3: manager, administrator, auditor) **and** logbook access (view or review), restricted logbooks respected; `logbook.manage` sees all. Catalog 1.2.0. |
| 12 | Web viewer | Routes page with filters (date, person, resource, logbook, sheet, status) and one route on a Leaflet map: segments as recorded, dashed gaps, start/last markers, entry markers, figures (distance, moving time, accuracy, gaps, rejected), events. Tiles from `LOGBOOK_MAP_TILES` (OpenStreetMap by default); only tiles are fetched, coordinates never leave the server except to the viewer. |
| 13 | Export, removal, retention | GPX export (audited `track.exported`, `no-store`) for anyone who can see the route. Removal by managers only (audited `track.deleted`; entries keep their own location). Routes older than `LOGBOOK_TRACK_RETENTION_DAYS` (730) after they end are removed by `logbook:operations` (audited `track.expired`). Phone: ended, synced routes leave after 30 days. |
| 14 | Phases 2 and 3 | `/coming-soon/gps-devices` and `/coming-soon/fleet`: description and planned list only, no forms, no buttons, no APIs, no tables. `log_tracks.source` allows only `phone`. |

## 3. New dependencies

- Web: `leaflet` 1.9.4 and `react-leaflet` 5.0.0 (map display only).
- Phone: `expo-location` and `expo-task-manager` (~57.0.23), part of the Expo SDK already used. A custom entry `index.js` defines the location task before `expo-router/entry`.

No new services or infrastructure.

## 4. Not done / limits

- iOS background recording is off (`isIosBackgroundLocationEnabled: false`); the app is Android-first.
- Not run on a device: needs a new native build (new permissions and native modules). Battery, Doze and OEM killers (Xiaomi, Huawei…) must be checked on real phones.
- Google Play requires the foreground-service-location declaration (and a short video) for the new build.
- The phone does not show a map (the web does).
