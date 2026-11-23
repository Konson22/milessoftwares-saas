# ADR-0030: Miles Logbook: recording modes, optional shifts and resource fuel estimates

Status: **Accepted (2026-11-23), built phase by phase.** Chosen with the user: the activity lives on the entry (§4.4); explicit occurrence association with the legacy match kept for old phone builds (§4.3); phase-by-phase delivery (§9).
Extends ADR-0025 to 0029. Same app (`logbook-app`), same phone app (`logbook-mobile-app`), same database.

## 1. Existing functionality that can be reused (audited)

| Area | What exists | File(s) |
|---|---|---|
| Entry engine | One engine for every sheet: drafts, idempotent create, submit, revisions (`log_entry_revisions`, immutable), review with separation of duties, attachments, audit. | `Entries.php`, `Entry*` models |
| Template versions | `log_sheet_versions`: `fields` json + `location` json (GPS mode), immutable once published; an entry keeps its version. The right place for per-sheet behaviour that must not rewrite history. | `Sheets.php`, ADR-0028 §2.1 |
| Fields | 13 types incl. `integer`/`decimal` (min/max, normal range, unit, auto-exception) and `resource` (stores the resource **UUID**, filtered by `resource_type`). No calculated fields. | `Fields.php` |
| Schedules | `log_schedules` daily/weekly/monthly, opens/due time, late window, timezone, responsible person, completion (submitted/reviewed), reminders + push, pause/resume/end. `log_schedule_occurrences` with status open/completed/late/missed/excused, timeliness, excuse reason, link to the entry; generation is idempotent and catches up 7 days. | `Schedules.php`, `Recurrence.php` |
| Resources | `log_resources` code, name, free-text type, location, active. Nothing about fuel or meters. | `ResourceController.php` |
| GPS routes | `log_tracks`: person, logbook, sheet, resource, task, start/end, status, server-computed distance; entries link with `log_track_id`. Phone records trips in the background-safe way of ADR-0028. | `Tracks.php`, `Route.php` |
| Incidents | Exceptions (manual, from readings or checks) link logbook, sheet, entry, resource, field and recorded value; tasks; investigations (ADR-0029) link entries at a revision, exceptions, tasks, routes. | `Exceptions.php`, `Investigations.php` |
| Reports | One filter set (logbook, sheet, person + part, dates by happened/recorded, resource, location, status, review, flags) for overview, table, timeline, trends, map, CSV, PDF; schedule figures (due, on time, late, missed, excused) in `Issues.php`. | `Reports.php`, ADR-0029 |
| Phone | Offline SQLCipher store, one sync engine (idempotent by phone id, backoff, 409 handling), kit download (sheets, versions, people, **resources**), due list with occurrences (`occurrence_id` sent on create), trips. | `sync.ts`, `KitController.php` |

## 2. Missing requirements

1. **Recording mode** per sheet (scheduled / event-based / on-demand): absent. Today every sheet is effectively on-demand, and it becomes "scheduled" only because a schedule points at it.
2. **Several readings a day on one schedule**: absent. Occurrences are unique on `(schedule_id, occurs_on)`, so 06/12/18/00 needs four schedules today.
3. **Overnight windows**: refused (`due_time` before `opens_time` fails validation), so a 00:00 reading cannot open at 23:30.
4. **Activity sessions** (start, end, measurements, optional GPS): absent, except trips, which are GPS-only.
5. **Shifts**: absent (no table, no field, no filter).
6. **Fuel**: absent (no rate, no rate history, no meter readings, no calculation snapshot).
7. **Meter sequence checks** (odometer and hour meter going backwards, replacement or reset): absent.
8. Reports by recording mode, by shift, and fuel summaries: absent.

## 3. Duplicated or conflicting functionality

- **Extra entries satisfy occurrences automatically.** `Schedules::entrySubmitted()` and `matchExisting()` give an occurrence any entry whose time falls in its window. The spec says an extra reading must not count unless explicitly associated. This conflicts with the offline design (an old phone build sends no occurrence). The plan resolves it in §4.3 without breaking old builds.
- **Trips versus activity sessions.** A trip already has a start, an end, a resource and a person. A second "session" entity beside it would be two sources of truth for when an activity happened. The plan keeps the trip for GPS only and puts the activity on the entry.
- **Where the schedule lives.** The spec allows schedule settings in the template; the Schedules module already owns them. The plan keeps one owner (Schedules); the template only says the sheet is "scheduled".
- **Numeric fields versus fuel figures.** Fuel litres typed into ordinary decimal fields would be added up by Trends like any other number. Fuel figures go in one calculation snapshot, so they are counted once.

## 4. Proposed architecture

### 4.1 Recording mode (template version)
`log_sheet_versions.recording` json, published with the version like `location`:
`{ mode: on_demand | scheduled | event, activity?: { measure: distance | hours | none, start_field, end_field, duration_field?, actual_field?, refill_field?, resource_field }, shifts: off | optional | required }`.
Null means `on_demand` (every existing sheet keeps today's behaviour). Changing the mode needs a new published version; old entries keep theirs.

### 4.2 Scheduled sheets
- `log_schedules.times` json: a list of `{opens, due}` wall times; the old single `opens_time`/`due_time` becomes the first item, and the columns are kept.
- `log_schedule_occurrences.slot` (small int, default 0), with the unique key changed to `(schedule_id, occurs_on, slot)`. Existing rows are slot 0, so no data changes.
- Overnight: a window whose due time is before its opening time closes on the next day (`occurs_on` is the opening day). This is tested across midnight and across a timezone change.
- Only the scheduler creates occurrences; it never creates entries.

### 4.3 Association with occurrences (the conflict above)
- An entry carries either an explicit `schedule_occurrence_id` (the web and phone pick it; the current due one is preselected) or an explicit `unscheduled: true` ("extra reading").
- The window rule still applies to a named occurrence.
- Legacy entries (neither flag, from old phone builds) keep today's automatic match, so nothing breaks before the phone update. A workspace setting can switch that off later.

### 4.4 Event-based sheets: the entry is the session
An event sheet's entry is started as a draft ("Start trip") and ended later. New columns on `log_entries` and `log_entry_revisions`: `activity_started_at` and `activity_ended_at`.
- The draft already survives app restarts and lost signal (SQLCipher store and sync), so an unfinished activity is an unsubmitted draft. The list shows it as "in progress".
- An optional trip links through the existing `log_track_id`.
- Related observations are separate entries with `activity_entry_id`, pointing at the session entry.
- No new scheduler: an event sheet refuses a schedule.

### 4.5 Shifts (optional)
- `log_shifts`: name, start, end (wall times, may cross midnight), optional logbook, active. Overlapping active shifts in the same scope are refused, so a time matches at most one shift.
- `log_shift_members` (optional, many to many): it only suggests; it never restricts who may write.
- `log_entries.shift_id` (and on revisions): suggested from the occurrence time, editable, and fixed in each revision. Renaming a shift does not change past entries, because the id stays.
- No attendance, rostering or payroll.

### 4.6 Resource fuel settings and rate history
- `log_resource_fuel_rates`, append-only: resource, fuel type, method (`distance` in L/100 km, or `hours` in L/h), rate (decimal), effective from (date-time), note, created by. The rate in force at the activity's time is the latest row with `effective_from` at or before it.
- Correcting a rate means a new row. Past entries are never recalculated.
- Managers only (`logbook.manage`), audited.
- A resource with no rows has no fuel calculation (rooms, computers…).

### 4.7 Calculation snapshot (no formula engine)
- `log_entries.calculation` json, also copied into each revision:
  `{ method, resource_id, inputs: {start, end, duration?}, quantity (km or h), quantity_source (odometer | hour_meter | recorded_duration), rate: {id, value, unit, effective_from}, estimated_l, actual_l?, refill_l?, variance_l?, calculated_at, by: server | phone, phone_rate_id? }`.
- Formulas: estimated = km × rate ÷ 100, or h × rate; variance = actual − estimated, only when an actual figure was measured. A refill is shown and never used as the actual figure.
- The server recalculates at submit with its own rate history.
- When the phone's offline figure used another rate, the entry keeps the server figure and records `phone_rate_id` and the phone's number. It is flagged "rate changed since recorded" for the reviewer, never silently overwritten.
- GPS distance is shown beside the odometer distance, and is never substituted for it.
- Variance is labelled "difference from estimate" and nothing more. It raises an exception only if the sheet's existing range rule says so (an `expected_max` on a variance field is not added automatically).

### 4.8 Meter checks
- Start ≤ end, and both are nonnegative.
- The start reading is compared with the last submitted end reading of the same resource and measure. If it is lower, submit is refused with a clear message, unless a **meter reset** is recorded.
- `log_resource_meter_events` (resource, measure, kind replaced/reset/corrected, new reading, at, reason, by) is managed in Resources and audited.
- Two activities on one resource whose meter ranges overlap are flagged, not added twice.

### 4.9 Reports
- New filters: recording mode and shift.
- Scheduled sheets keep the expected, on-time, late, missed and excused figures. Event sheets get an activity table (reference, resource, operator, start, end, duration, km or h, estimated, actual, variance, incidents, route).
- Fuel per resource, per operator and per shift, and over time, is built from snapshots only (one figure per entry, latest revision), so instantaneous readings such as tank level are never added up.
- On-demand sheets show no expected or missed figures.
- The ADR-0029 record panel gains the calculation block, drill-down and exports.

## 5. Database changes (all additive)
- `log_sheet_versions.recording` json.
- `log_schedules.times` json.
- `log_schedule_occurrences.slot`, with a unique-key change (drop and recreate the index; the data is unchanged).
- `log_entries` and `log_entry_revisions`: `activity_started_at`, `activity_ended_at`, `activity_entry_id`, `shift_id`, `calculation` json, `unscheduled` bool.
- New tables: `log_shifts`, `log_shift_members`, `log_resource_fuel_rates`, `log_resource_meter_events`.
- All new tables use tenant composite keys; pgsql CHECKs on method, unit and kind.

## 6. API changes
- **Web:** sheet builder recording section; schedule times list; shifts CRUD; resource fuel rates and meter events; entry form with occurrence or extra, shift, activity start/end and a live estimate.
- **Phone API:** the kit gains `recording` per version, `shifts`, and per resource `fuel: {method, rate_id, rate, unit, effective_from}` plus the last meter reading. Entry create/update accepts `occurrence_id | unscheduled`, `shift_id`, `activity_started_at/ended_at`, `activity_entry_id` and `calculation` (the phone's figure, kept as evidence). A 409 `meter_backwards` needs a person.
- **Catalog:** no new permission (fuel settings use `logbook.manage`; shifts use `logbook.manage`).

## 7. Web and Android changes
- **Web:**
  - builder "Recording" section (mode, activity measure and fields, shifts);
  - Schedules: several times;
  - Resources: Fuel tab (rate history, add rate) and Meter tab (reset/replace);
  - new Shifts page;
  - entry form: occurrence picker, shift, start/end, calculated block (labelled *estimate*);
  - Reports additions.
- **Android:**
  - due list per slot;
  - "Extra reading";
  - event sheets: Start → in progress → End with end readings, then a live estimate from the kit rate;
  - optional "Record route" on start (the existing trip);
  - the shift picker;
  - everything stored in the existing SQLCipher store and synced by the existing engine.
  - Needs a new EAS build and a device test; neither can be done from here.

## 8. Security and historical-data risks
- **Rates:** a rate changed after an offline calculation is handled by §4.7 (keep both, flag, never overwrite).
- **Occurrence match:** changing how entries meet occurrences must not move past links; only new submissions use the new rule.
- **Unique-key change:** the change on occurrences runs in a transaction and has been checked on a copy of production.
- **Access:** fuel settings and meter events are manager-only. Resource and trip data in reports stay within `Access::entries()` and `Tracks::visible()`, and the workspace scope.
- **Labelling:** variance shows no wording of theft, misuse or fault.

## 9. Implementation phases
1. **Schema and recording mode:** the migrations, `recording` in the builder (web), mode-aware entry form; on-demand unchanged. Tests: modes side by side in one logbook, old entries untouched.
2. **Schedules:** several times, slots, overnight windows, explicit association and extra readings. Tests: 06/12/18/00, midnight, retries, legacy auto-match.
3. **Shifts:** tables, page, suggestion, entry stamp, filter. Tests: overnight, overlap refused, unassigned, renamed shift.
4. **Fuel settings:** rate history, meter events, Resources tabs. Tests: effective dates, no configuration, permissions.
5. **Activities and calculation:** start/end on entries, snapshot at submit, meter checks, variance, revisions. Tests: both formulas, rate change, reset, refill independence, overlapping meter ranges.
6. **Reports:** mode and shift filters, activity table, fuel summaries, record panel, exports. Tests: aggregation without double counting, historical rates, export consistency, cross-tenant.
7. **Android:** kit, slots and extra reading, start/end activity, offline estimate, conflict flag. Jest tests for the calculation and the sync. Then an EAS build and a device check.
8. **Deploy:** migrations, catalog (only if it changes), regression suite.

**Progress (2026-11-23):** phases 1–3 built, tested and deployed together.
- Phase 1: `log_sheet_versions.recording` json (`mode`, later `shifts`). Tests in RecordingModesTest.
- Phase 2: `log_schedules.times` and `log_schedule_occurrences.slot`, with the unique key on schedule, day and slot. `log_entries.schedule_link` is named, extra or null; null keeps the legacy time match. The entry form has a reading picker and the schedule form a times editor. Tests in ScheduledReadingsTest.
- Phase 3: `log_shifts` and `log_shift_members`, plus `shift_id` on entries and revisions. The template sets off, optional or always. The form suggests a shift from the time, then the person's linked shift. Report filter by shift; shifts in the phone kit. Tests in ShiftsTest.

## 10. Testing and deployment requirements
- Every test listed in the brief, in Pest per phase.
- Jest for the phone's calculation, its rate choice and sync payloads.
- The full logbook suite, phpstan, tsc, build.
- **Deploy:** the existing release process to 2.25.5.75, migrations checked on a production copy, then a smoke test of a scheduled, an event and an on-demand sheet.
- **Not verifiable from here:** device behaviour (needs the EAS build installed on an Android phone).
