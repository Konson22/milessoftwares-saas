# ADR-0032: Miles Logbook: vehicle trips, driver assignments, dispatched trips and stops

Status: **Accepted (2026-11-24), phases 1 to 3 built; not committed, not deployed.** Chosen with the user:
one entry is one trip and carries its stops; managers assign drivers to vehicles both standing and
per trip (dispatch); on a vehicle movement sheet a driver records only the vehicles assigned to
them; the stops list is built for vehicle movement only (not a general repeating section). Built
phase by phase, reported after each. Extends ADR-0025 to 0031. Same app (`logbook-app`), same phone
app (`logbook-mobile-app`), same database.

## 1. The ask

"The vehicle movement driver has to record each point he stops at; the driver can be assigned to a
vehicle; only a manager can assign; the driver just fills in the start time, mileage, fuel, etc."

## 2. What existed

| Area | What existed (ADR) | Reused as |
|---|---|---|
| Event-based sheets | Entry with an activity start and end (0030). | One entry = one trip. |
| Readings | Start/end odometer, checked against the last end reading, meter events (0030). | Trip mileage; stops checked between them. |
| Fuel | Rates per vehicle, estimate at submission, fuel measured, refill (0030). | Trip fuel. |
| Assets | Vehicles as resources in the Vehicles category, main asset field, linked sheets (0031). | The vehicle of a trip. |
| GPS | Optional phone trip track (0028). | A stop can carry a pin; the route stays the track. |
| Starter | Vehicle Movement starter (0031). | Gains stops and assignment settings (phase 3). |

Missing: who may drive a vehicle, planned trips, and the points stopped at.

## 3. Phase 1: driver assignments (built)

1. **`log_resource_assignments`**: asset, person, `starts_on`, `ends_on` (days in the workspace
   time zone, both inclusive; none = until ended), note, who assigned, `ended_at`/`ended_by`. Works
   for any asset; the page says "Drivers" for the Vehicles category.
2. **Only managers** (`logbook.manage`) assign and end, on the asset page (Drivers tab). Several
   drivers per vehicle and several vehicles per driver are allowed; the same person twice on the
   same asset for overlapping days is refused. Ending sets the last day to today (the driver may
   still record today's trips); ending one not started yet cancels it (last day before first, so
   it covers nothing). Never deleted. Audited: `resource.assigned`, `resource.assignment_ended`,
   `resource.assignment_cancelled`.
3. **Sheet setting `recording.assigned_only`** (template builder, Main asset panel). Needs a main
   asset field. On such a sheet:
   - the web form offers the author only the assets assigned to them that day (the entry's day
     when editing); none assigned → nothing to choose and a line saying a manager assigns;
   - **the server refuses at submission** an asset not assigned to the author on the day the
     activity started (`activity_started_at`, else `occurred_at`), whatever the client offered.
     A draft may be saved; it cannot be sent.
4. **Phone kit**: each sheet carries `assignedOnly`; the kit carries the person's assignments that
   are not over (`resourceId`, `from`, `to`) so the phone can filter offline by day (phase 4).
   Older phone builds ignore both; the server still refuses.

## 4. Phase 2: dispatched trips (built)

1. **`log_dispatches`**: logbook, sheet, vehicle, driver, `planned_on`, optional `planned_time`
   (H:i, workspace time), destination, purpose, status, `log_entry_id`, who planned, cancellation
   (when, who, reason). **`log_entries.dispatch_id`**, unique: one entry per planned trip.
2. **Managers** plan and cancel on the **Trips** page, for sheets of the logbooks they manage. The
   sheet must name an asset; the vehicle must be one its main field takes (type or category, and
   its linked assets); the driver must write on that sheet; the day may not have passed.
3. **Lifecycle**: planned → started (the driver's entry exists as a draft) → done (entry recorded);
   planned → cancelled (manager, optional reason the driver sees). A started trip can no longer be
   cancelled. Discarding the draft puts the trip back to planned.
4. **For its day a planned trip (not cancelled) assigns its vehicle to its driver**, so
   assigned-only sheets accept it without a standing assignment.
5. **Starting**: "Start trip" opens the entry form with the vehicle chosen and the plan shown; the
   entry is made with `dispatch_id`. Only the driver may record it, once. At submission the vehicle
   must be the planned one, and a cancelled trip is refused.
6. **Notices** (category task): the driver is told when a trip is planned and when it is cancelled.
   Audited: `dispatch.planned`, `dispatch.cancelled`.
7. **Phone**: the kit carries the driver's open trips from yesterday on (`dispatches`); the entry
   API takes `dispatch_id`. The phone screens come in phase 4.

## 5. Phase 3: stops inside a trip (built)

1. **`log_entries.stops`** and **`log_entry_revisions.stops`** (json): each stop has place (text,
   required), `arrived_at` (required), `departed_at`, `odometer`, `purpose`, `note`, `pin`
   (lat, lng, accuracy). Times kept as UTC ISO strings; the web form sends workspace-local times,
   the phone ISO times with their offset. At most 50 stops.
2. **Sheet setting `recording.stops`**: off (default), optional, required. **Event-based sheets
   only** (a trip has a start and an end); refused on other modes.
3. **Checked whenever saved**: shape (place, times readable, departure not before arrival,
   odometer a number, pin valid). **Checked at submission**: required → at least one; each stop
   inside the trip (not before its start, not after its end); in the order they happened; odometer
   never below the previous stop (or the trip's start reading) nor above the trip's end reading
   (when the sheet's activity measures an odometer). A draft may be saved with stops added one by
   one as the trip goes; a save that sends no stops keeps them.
4. **Web**: a Stops panel on the trip form (add a stop with the arrival set to now, "Now" for the
   departure, "Add my location" from the browser); a numbered timeline on the entry page with the
   minutes stopped and the km from the previous point; builder setting "Trips record their stops".
   Reports → Activities: **Export stops** (CSV, one line per stop, the report's filters).
5. **Phone**: the kit sends `recording.stops`; the entry API takes and returns `stops`.
6. **Vehicle Movement starter** (new sheets only): stops optional, assigned-only on, the `driver`
   person field removed (the driver is the author), fuel gauge at start and at end added.

## 6. Phase 4: phone (built)

1. **Assigned only, offline** (`src/logbook/vehicle-trips.ts`): the vehicle field of an
   assigned-only sheet offers only what the kit's `assignments` and `dispatches` give the driver on
   the trip's day (the phone's calendar day of the start time). A vehicle not assigned that day is
   flagged and the entry is not sent.
2. **Planned trips** on Home: the driver's planned trips not begun on the phone, each with Start.
   Start opens one draft per trip with the vehicle chosen and sends `dispatch_id` when the entry is
   first made; starting it again opens the same draft.
3. **Stops** on the trip form when the sheet records them: "Arrived at a stop" (time now, place,
   purpose, odometer, note, optional GPS pin), "Leaving now", change or remove. A new arrival closes
   the stop still open. The server's checks (inside the trip, in order, odometer between the trip's
   readings) run on the phone before sending, with the same error keys. Stops are sent as ISO times
   and read back from the server; the entry page lists them.
4. Start time, mileage and fuel stay the sheet's own fields (starter: odometer start/end, fuel
   start/end). Needs a new native build; not yet run on a device.

## 7. Consequences

- Additive migrations only; sheets without the new settings behave as before.
- The server is the one judge of assignment; a phone offline may let a driver start a trip on a
  vehicle whose assignment was ended meanwhile, and the submission is refused with the reason.
