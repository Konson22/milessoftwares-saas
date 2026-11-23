# ADR-0029: Miles Logbook: actionable reports, record review and investigations

Status: **Accepted (2026-11-23). Built locally, uncommitted, not deployed.**
Extends ADR-0025 to 0028. Same app (`logbook-app`), same database, catalog **1.4.0**. No phone change.

## 1. What existed before

- Reports: counts by status, day, logbook and contributor; number-field totals for one sheet; the "Needs attention" table (`App\Logbook\Issues`); CSV and PDF exports. Filters: logbook, sheet, status, review state, dates (by when it happened), search.
- Tasks, exceptions, reviews, revisions, audit trail and GPS routes, each with its own lifecycle (ADR-0026, 0028).
- **No investigation, incident or case record anywhere.** Nothing linked several records together.

## 2. Decisions

| # | Topic | Decision |
|---|---|---|
| 1 | Filters | Added to `Reports::rules()` and `Reports::query()`, so every view and export uses the same rules: `person` with `relation` (`any`, `created`, `reviewed`, `named` in a person field), `basis` (`occurred` default, or `submitted`), `resource` (resource fields, matched in the values JSON), `location` (resources at that place or any place under it), `flag` (`exceptions`, `open_exceptions`, `abnormal` = an exception from a reading or a check). Dates are workspace days. Drafts never count. Everything stays within `Access::entries()`. |
| 2 | Views | One Reports page with `view` = overview, table, timeline, trends, map. **Table**: 50 per page; with one sheet, its fields as columns (read with each entry's own version), with readings outside the normal range marked ▲/▼. **Timeline** (`App\Logbook\Timeline`): entries (happened and recorded), later revisions, reviews, exceptions raised/resolved/closed, tasks given/completed, routes started/ended; newest first, up to 300 events; every time labelled HAPPENED or RECORDED. **Trends** (`App\Logbook\Trends`): numeric fields of one sheet across its published versions, per workspace day (count, total, average, lowest, highest); a unit change splits the series. **Map**: only coordinates captured with entries (up to 500), plus the recorded routes in scope (up to 50); nothing drawn that was not recorded. |
| 3 | Record panel | `GET reports/records/{entry}` → an Inertia UI Modal slide-over over Reports (`baseRoute('reports.index')`; a direct visit renders Reports). Shows answers with range flags, files, revisions with their reviews, exceptions and tasks the viewer can see, location and route link, history (auditors and logbook managers). |
| 4 | Actions from a record | Open / review the entry; report an exception (`exceptions.store` with `log_entry_id`); give a task (`tasks.store`, `logbook.tasks.manage` only); link to an open investigation or start one; see the resource's timeline. **No new action endpoint**: each goes through the existing rules (separation of duties, lifecycles, revisions). |
| 5 | Investigation model | `log_investigations` (reference `INV-YYYY-NNNNNN`, title, description, logbook (required: access follows it), resource, incident time, period, investigator, status, closure decision, closed/reopened, revision), `log_investigation_evidence` (kind entry/exception/task/track, subject id, **entry revision**, role evidence/corrective, label, note, added by/at, removed by/at with reason), `log_investigation_notes` (kind observation/hypothesis/finding/conclusion/follow_up, body, version, supersedes). Tenant composite keys; pgsql CHECKs. |
| 6 | Lifecycle | Open → Investigating → Findings recorded (needs a current finding) → Closed (needs a finding and a closure decision of 10+ characters). Findings recorded can go back to Investigating. Closed → Investigating only by reopening with a reason; the earlier decision is kept. A closed investigation takes no changes. Stale copies refused (`revision`). Every step audited. |
| 7 | Evidence integrity | Links, never copies: only the reference label is stored. An entry is linked **at a revision** (the current one unless named; never a future one); a later correction does not move the link; another revision can be linked beside it. The same record and revision twice is refused. Removing a link marks it removed, with a reason; nothing is deleted. Notes are never edited: a new wording supersedes the old, which stays. |
| 8 | Restricted evidence | Linking needs access to the record (`Access::entries`, `Exceptions/Tasks/Tracks::visible`). On display, each item is checked with the **viewer's** access: one they could not open shows as "Restricted" with only kind, who added it and when; no label, id, note, removal reason, timeline event or PDF line. Audit details hold the evidence row id, not the record's reference. Opening an investigation grants nothing. |
| 9 | No automatic cause | Logbook never proposes a cause, a person to blame or a score inside an investigation; everything in it was written by a person or is a reference. The PDF says so. |
| 10 | Corrective actions | `investigations/{id}/tasks` creates an ordinary task through `Tasks::create` (logbook and resource from the investigation; `logbook.tasks.manage` required) and links it with role `corrective`. |
| 11 | Permissions | New `logbook.investigations.manage` (logbook manager and administrator) to open and run investigations. `logbook.audit.view` reads them. Everyone else: 403 on the list, 404 on one. The nav item shows for either permission. |
| 12 | Exports | Reports CSV/PDF honour the new filters. Investigation PDF: organisation, reference, title, status, logbook, resource, incident time, period, investigator, opened/closed, description, evidence timeline, readable evidence (restricted count only), current notes by kind, corrective tasks, closure, generation time and timezone, printed by. Audited (`investigation.printed`). |

## 3. Not done

- No anomaly detection, prediction or root-cause logic (out of scope by decision 9).
- Timeline and map are capped (300 events, 500 locations, 50 routes) and say so; older items need narrower filters.
- Investigations are web only; the phone app does not show them.
- The new pages were type-checked, built and covered by feature tests, but not looked at in a browser.

## 4. Tests

`tests/Feature/ActionableReportsTest.php` (9) and `tests/Feature/InvestigationsTest.php` (12): combined filters, workspace-day boundaries, table columns and flags, timeline order and basis, trends, record modal and direct visit, restricted entries and records, cross-workspace isolation, read-only reports; investigation reference and first link, exact revision kept across a correction, link by reference and removal as a mark, note wordings kept, lifecycle with close/reopen rules, stale copies, corrective task, who may run or read, restricted evidence hidden (props and history), PDF, workspace isolation, linked records unchanged.
