# ADR-0031: Miles Logbook: administrator-managed assets, templates and operational reports

Status: **Accepted (2026-11-23), all phases built; not committed, not deployed.** Chosen with the user:
managers keep the asset rights they had (`logbook.manage`, no new permission, no catalog change); a
monitored place is a resource of kind `monitored_location`, tied to a Location; an entry's asset is
the sheet's *main* resource field, stored in `log_entries.resource_id`; build every phase, then report.
Extends ADR-0025 to 0030. Same app (`logbook-app`), same phone app (`logbook-mobile-app`), same database.

## 1. What existed (audited)

| Area | What existed | Reused as |
|---|---|---|
| Resources | `log_resources`: code, name, free-text type, location, active; fuel rates and meter events (ADR-0030). | The asset register. Extended, not replaced. |
| Locations | `log_locations`, nested. | Where an asset or a monitored place is. |
| Resource fields | A sheet field storing a resource UUID, filtered by `resource_type`. | The link between a sheet and its assets. |
| Starter templates | 4 sheet starters (`StarterTemplates`). | Grown to 9, with recording and GPS settings. |
| Schedules | Occurrences with timeliness kept apart from review (ADR-0027/0030). | Per-asset schedules and compliance. |
| Reports | One filter set for every view and export (ADR-0029). | New asset filters, two new views, two new exports. |
| Phone | Offline kit with resources, sync, due readings. | Linked assets and the main field in the kit. |

Missing: asset kind and status, identity fields (make, model, serial, responsible), details,
asset templates, links between sheets and assets, per-asset schedules, an entry's own asset,
asset and compliance reports, and a rule against totalling non-additive readings.

## 2. Decisions

1. **Assets are resources.** `log_resources` gains `kind` (`asset` | `monitored_location`), `status`
   (`active` | `inactive` | `retired`, kept in step with the old `active` flag by a model hook),
   `manufacturer`, `model`, `serial_number`, `responsible_id`, `metadata` (label, value, unit per
   detail) and `template` (a snapshot of the template it was registered from). A monitored
   location must name its Location.
2. **Asset templates.** Seven starters in code (`AssetTemplates::starters()`), and the workspace's
   own in `log_asset_templates` (`version` increases on every change). An asset keeps the snapshot
   it was made from; later versions never touch it. Starter names are reserved.
3. **Sheet starters.** Nine (`StarterTemplates`): Daily Activity, Generator Operation, Vehicle
   Movement, Temperature Monitoring, Equipment Inspection, Equipment Maintenance, Water Pump
   Operation, General Incident Report, IT Maintenance. Each carries its recording mode, its main
   asset field and, where it fits, activity and GPS settings. No exception is raised without a
   range the manager sets.
4. **Main asset.** `recording.subject` on the sheet version names the resource field the entry is
   about; empty means the activity's resource field, else the only resource field
   (`Recording::subjectField`). Entries store it in `log_entries.resource_id` on create, update
   and submit; exceptions and tasks take it as their default resource.
5. **Links.** `log_sheet_resources` (unique per sheet and asset) links many assets to many sheets.
   With links, a sheet's main field accepts only linked assets (server rule; web and phone offer
   only those). Without links, the field's category rules as before. Links are refused for
   archived sheets, retired assets, sheets with no main field, or a category mismatch. Unlinking
   is refused while that asset's schedule on the sheet is live.
6. **Per-asset schedules.** `log_schedules.resource_id` and `log_schedule_occurrences.resource_id`.
   An asset's occurrence is met only by an entry about that asset, whether named explicitly or
   matched by time. A schedule keeps its asset.
7. **Retiring never rewrites history.** A retired asset keeps its records, exceptions, tasks and
   reports. A draft naming it is refused on submit, with the reason; it is never moved to another
   asset, on the web or on the phone.
8. **Never total what does not add up.** Number fields have an optional `summable` setting; without
   it, units such as °C, %, bar, psi, V, Hz and rpm are never totalled (`Fields::summable`).
   Reports show the average, lowest and highest for every reading, and a total only for summable ones.
9. **Reports** (`AssetReports`): by asset (records, last record, out of range, open exceptions,
   expected checks on time, missed, fuel); readings per asset for one sheet; scheduled compliance by
   sheet and asset (expected, on time, late, missed, excused, awaiting review, open, completion).
   Asset, category, kind and location filters apply to every view and export. Each export states
   the period and filters at its head (`Reports::describe`). Counts are drill-downs to the records.
10. **Rights unchanged.** Reading assets and templates: anyone in Logbook. Registering, changing,
    linking, retiring, templates: `logbook.manage`. Reports and exports: `logbook.reports.view`,
    within the logbooks the member reaches.

## 3. Migration

`2026_11_24_100000_add_assets_templates_and_links.php`, additive only: new columns default safely
(`kind` asset, `status` from `active`); new tables; `resource_id` columns nullable. Existing entries
are backfilled from the activity's resource field, else the only resource field; entries with
several resource fields and no rule stay without an asset. Nothing is dropped.

## 4. Phone

The kit gives each sheet `subject` and `assets` (linked ids) and each resource its `kind`;
`sheet-versions/{id}` gives `subject`; entries return `resourceId`; expected readings carry
`resourceId`. Offline, the main field offers only linked assets, the readings offered follow the
chosen asset, and a draft whose asset is no longer offered shows a warning and is not sent until
another is chosen (`src/logbook/assets.ts`). Older phone builds keep working: the server enforces
the links.

## 5. Not done

- No PDF of the readings-per-asset table (the CSV and PDF exports cover the asset and compliance tables).
- No bulk import of assets.
- The phone was type-checked and unit-tested, not run on a device; an EAS build is needed.
