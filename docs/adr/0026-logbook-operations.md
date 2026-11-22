# ADR-0026: Miles Logbook operations: tasks, schedules, exceptions and notifications

Status: **Accepted (2026-11-20). Built locally (§5), uncommitted, not deployed.**
Extends ADR-0025 (Miles Logbook). Same app (`logbook-app`), same phone app (`logbook-mobile-app`), same database.

## 1. Audit (2026-11-20, verified in code)

| # | Area | Finding |
|---|---|---|
| 1 | Backend | Laravel 13, domain in `App\Logbook` (static service classes, one transaction per change, row locks). Controllers are thin. |
| 2 | Schema | One migration (`2026_11_19_100000`), tenant-keyed tables with composite `(organization_id, id)` FKs, PostgreSQL triggers for append-only tables and immutable published versions, CHECK constraints on statuses. |
| 3 | Web | Inertia 3 + React 19, `@miles/ui` shell, Wayfinder routes, navigation in `resources/js/lib/navigation.ts`, permission checks in `lib/access.ts`. |
| 4 | Android | Expo 57 + expo-router, zustand, SQLCipher store with rows scoped `workspace|user`, a pure-TS sync protocol (`src/logbook/sync.ts`) tested in Jest against a lossy fake. |
| 5 | Sign-in | Central OIDC through `platform-client`; bearer tokens for the API (`platform.api`). |
| 6 | Tenancy | `BelongsToTenant` (fail closed) + composite FKs. The organization comes from the host. |
| 7 | Roles | Catalog `logbook` 1.0.3: 7 permissions, 6 roles. `Access` adds per-logbook lists (contributor, reviewer, viewer). Owners hold every permission. |
| 8 | Logbooks, sheets | Immutable published versions; field engine `App\Logbook\Fields` (12 types, hard limits, normal range). |
| 9 | Versioning, review | Revisions and reviews append-only; no self-review; references at first submission. |
| 10 | Audit | `log_audit_events`, append-only on PostgreSQL. `AuditTrail::record` takes logbooks, sheets and entries only. |
| 11 | Offline | Idempotent create (phone UUIDv7), revision-based update and submit, hash-deduplicated uploads, 409 codes never retried, unsent work kept at sign-out. |
| 12 | Jobs | Database queue (`miles-product-queue@logbook`) and a per-minute `schedule:run` timer exist on production. Only `platform:outbox` is scheduled. No command works across tenants yet. |
| 13 | Notifications, mail | **None.** No in-app notifications, no mail classes. Products' production mailer is `log` (mail is discarded). No push library in the phone app. |
| 14 | Entitlement | `platform-client`: unentitled workspace 404, member without access 403, checked on every request. `PlatformApi::effectiveAccess(org, user)` answers for any member (cached). |
| 15 | Tests | Pest, `InteractsWithPlatform` fake; 87 pass on SQLite (2 PostgreSQL-only skipped). Phone: Jest 11, typecheck, lint. Deploy: `miles-deploy` with `PRODUCTS` including `logbook`. |

### 1.1 Status of each requested feature

| Feature | Status before this ADR |
|---|---|
| Tasks, assignment, verification | Missing |
| Reminders, notifications (in-app, email, push) | Missing (no infrastructure) |
| Recurring tasks, scheduled log sheets, missed entries | Missing (ADR-0025 §6 deferred) |
| Exceptions and corrective actions | Partial: out-of-range readings are flagged, not followed up |
| Conditional fields | Missing (ADR-0025 §6 deferred) |
| Resources and locations | Missing |
| Reporting filters and summaries | Partial: date, logbook, sheet, person, status, keyword; counts and number stats |
| Related entries | Missing |
| QR codes | Missing; no camera/barcode library in the phone app |
| Offline sync | Implemented and tested for entries; nothing for tasks |
| Audit | Implemented for entries, sheets, logbooks, access |

### 1.2 Defects found

1. **Reference year in UTC.** `References::next` used the server year, so an entry submitted just after midnight on 1 January in a UTC+ workspace got the previous year. Fixed: the workspace's time zone decides. Existing references are not touched.
2. **Web review is not tied to a revision.** The API sends the revision read; the web did not, so a reviewer could approve a resubmission they had not seen. Fixed: the web form sends the revision and a stale one is refused.
3. **Access checks only the signed-in user.** `Access` reads `Auth` and `Gate`, so it cannot answer "does the assignee reach this logbook?". Fixed by `App\Logbook\Member` (below).

## 2. Decisions

### 2.1 One app, additive schema

Everything is in `logbook-app` (`App\Logbook`) with one additive migration, `2026_11_20_100000_create_logbook_operations_tables`. No existing column changes meaning. Existing references, versions, permissions and audit rows are untouched.

### 2.2 Who can do what (catalog 1.1.0)

- New permission **`logbook.tasks.manage`** ("Assign and follow up work"): create, assign, reassign, cancel, reopen and verify tasks; manage recurring tasks; assign and close exceptions. Given to **Manager**, **Logbook administrator** and **Reviewer** (supervisors).
- Doing a task needs no permission beyond Logbook access: the assignee sees and works on their own tasks.
- Scheduling a log sheet needs `logbook.manage`.
- Reporting a problem (a manual exception) needs `logbook.entries.create` or `logbook.entries.review`, and the logbook within reach.
- Nobody verifies their own task or closes an exception they resolved.

### 2.3 Checking another member: `App\Logbook\Member`

`Member` is a user id plus a permission check. `Member::current()` uses the Gate; `Member::of($userId)` uses `PlatformApi::effectiveAccess` and is null when the person has no Logbook access (left, suspended, or the workspace's subscription ended). `Access` takes an optional `Member`, so the same rules answer for the assignee or a notification recipient. A task can only be assigned to someone who reaches its logbook (or sheet, or entry); otherwise the assignment is refused. A task never opens a restricted entry: the link is shown only to those who can read it.

### 2.4 Tasks

- Table `log_tasks`. Reference `TASK-2026-000001` from the existing `References` (sequence key `task`, which no sheet code can take; `TASK` and `EXC` become reserved sheet codes).
- Statuses: `pending`, `in_progress`, `awaiting_verification`, `returned`, `completed`, `cancelled`. **Overdue is derived** (`due_at` passed and status pending, in progress or returned).
- Completion: notes, evidence files, the time, and an optional linked entry. With verification required: `awaiting_verification`, then `completed` (verified) or `returned` with a comment. Without: `completed`.
- Cancel (with a reason) and reopen (to in progress). Tasks are never deleted.
- `revision` goes up on every change. The phone sends the revision it saw; a stale one answers 409 `stale` with the task as it is now, so a reassignment, cancellation or completion done meanwhile is never overwritten.
- Files: `log_work_files` (shared by tasks and exceptions; private disk, served through an authorized controller, SHA-256 deduplicated).
- History: the audit trail (`subject_type` task), with before and after values for assignee, due date and status.

### 2.5 Recurring tasks

- Table `log_task_series`: daily, weekly (chosen weekdays) or monthly (a day of the month), a start time, a due time, a time zone, an assignee, reminders.
- `logbook:operations` (every minute) creates the occurrences of the next 24 hours as ordinary tasks. Unique `(series_id, occurrence_on)` makes generation idempotent.
- Day 29 to 31 in a shorter month: the last day of the month. Local times are read in the series' time zone; a time skipped by a daylight-saving change moves forward to the next valid time.
- A paused or ended series makes no new occurrences; open ones stay. A changed assignee applies to occurrences not yet generated.

### 2.6 Scheduled log sheets

- `log_schedules` (a sheet, frequency, opens/due times, time zone, an optional responsible person, late window, reminders, completion rule `submitted` or `reviewed`) and `log_schedule_occurrences` (one row per expected entry, unique `(schedule_id, occurs_on)`), generated by the same command. A missing entry is therefore visible even when nobody writes anything.
- An occurrence is met by the first entry on its sheet (by the responsible person when one is set) whose time falls between opening and the end of the late window. Met before the due time: **completed**; within the late window: **late**; nothing by the end of it: **missed**. Managers can **excuse** an occurrence with a reason.
- An entry awaiting review counts as submitted (it is not missing). With the `reviewed` rule the occurrence completes only when the review lands; the submission and review times are both kept.
- Scheduled inspections are not tasks; they are linked only through notifications.

### 2.7 Exceptions

- Table `log_exceptions`, reference `EXC-2026-000001`. Statuses `open`, `in_progress`, `resolved`, `closed`, with reopen.
- Raised by hand (from an entry, or alone), or **automatically on submission** when a field asks for it: a number field with `raise_exception` (outside its normal range), a yes/no or single choice field with `exception_values` (a failed check). One automatic exception per entry and field (`auto_key`).
- The entry and its values are never changed. Corrective action is a **task linked to the exception** (`log_tasks.exception_id`); the exception does not copy task features.

### 2.8 Notifications and reminders

- **One engine** (`App\Logbook\Notifications`) for tasks, entries, schedules and exceptions. `log_notifications` holds in-app notifications (category, event, title, short text, link, read state). `dedupe_key` is unique per recipient, so a repeated event or reminder never notifies twice.
- Recipients are checked when the notification is made: they must hold Logbook access and reach the record. Text names a reference and a title, never values.
- **Reminders**: `log_reminders` rows (at start, minutes before due, at due, after due), recomputed when dates change, with the recipient resolved at sending time (the current assignee). `logbook:operations` sends due ones: it locks each row, re-checks the record and the recipient, records the outcome (`sent`, `skipped: …`), and never sends twice.
- **Email**: queued `LogbookNotice` mails for assignments, returns, overdue tasks, missed inspections and exceptions assigned, when the recipient's preference (default on) allows. Production products still use `MAIL_MAILER=log`, so mail is only delivered once Logbook's mailer is configured (as Central and Projects were).
- **Push: deferred.** It needs a Firebase project and `google-services.json` (a decision for the owner) and `expo-notifications`. The phone app shows the same notifications from the API instead, refreshed on open and on sync.

### 2.9 Conditional fields

A field may carry `show_if: {field, equals | in}` naming an **earlier** field (so there are no cycles) of type yes/no, single or multiple choice. Hidden fields are not required and their values are **cleared** when the entry is saved. The same rule runs in `Fields.php`, the web form and the phone's `fields.ts`. Published versions are unchanged; old versions without `show_if` behave as before.

### 2.10 Resources and locations

`log_locations` (with a parent) and `log_resources` (unique code per workspace, name, type, location, active). A new field type `resource` and an optional resource on tasks. Not an asset register: no values, depreciation or stock.

### 2.11 Deferred

Push notifications (§2.8), QR scanning (needs a camera/barcode library and the resource pages first), related-entry links, historical imports, notification preferences beyond email on/off per category.

## 3. Order of work

1. Defects (§1.2) and `Member`.
2. Notifications engine, reminders, `logbook:operations`.
3. Tasks and recurring tasks (web + API).
4. Scheduled log sheets.
5. Exceptions.
6. Overview, navigation, reports summaries.
7. Phone: tasks (offline progress and completion), notifications, due inspections.
8. Conditional fields (server, web, phone).
9. Resources and locations.

## 4. Testing

Pest feature tests per area (tasks, reminders, schedules, exceptions, notifications, conditional fields, isolation and access), on SQLite and PostgreSQL. Phone: Jest for the task outbox (stale state, repeats, lost replies) and conditional rules.

## 5. As built (2026-11-20)

**Server (logbook-app).**

- Migration `2026_11_20_100000_create_logbook_operations_tables` (additive: new tables only, composite tenant keys, PostgreSQL CHECKs; `down()` drops only these).
- Domain: `Member`, `Tasks`, `Recurrence`, `Reminders`, `Schedules`, `Exceptions`, `Notifications`, `WorkFiles`, `StaleRecord`; `Fields` gains `show_if`, `resource` and exception rules; `Entries` calls schedules, exceptions and notifications on submit and review.
- `logbook:operations` runs every minute from the scheduler, for every organization Logbook has seen (provisioned, or opened by a member), each inside its tenant; a failing workspace is reported and the rest go on. On prod it relies on the existing `miles-product-scheduler@logbook.timer`.
- Web: Tasks (mine / given / all / recurring), task page with work, verification and history; Exceptions list and page with corrective tasks; Schedules dashboard (completion rate, late, missed, waiting on review, excuse) and scheduling on any published sheet; Notifications with email choices per category and a bell in the shell; Resources and locations; Overview figures (overdue tasks, missed entries, open exceptions, my tasks, due today). Template builder: show-if, exception rules, resource fields.
- Phone API: `GET tasks`, `GET tasks/{id}`, `POST tasks/{id}/start`, `POST tasks/{id}/complete` (key + completed_at; 409 `not_assignee` / `not_open`), `POST tasks/{id}/evidence`, `GET due`, `GET notifications`, `POST notifications/read`; the kit carries resources and `can.manageTasks`.
- Catalog 1.1.0: `logbook.tasks.manage` for Reviewer, Manager and Administrator. Central must import the new catalog version on deploy.
- Defects of §1.2 fixed: reference year in the workspace time zone; web review tied to the revision shown; Access checks any member through `Member`.

**Phone (logbook-mobile-app).**

- Tasks tab (today's due inspections, open work, recent) and task screen: start and complete go through an encrypted outbox (`logbook/work.ts`) keyed by the phone; refused work is shown until dismissed. Evidence photos are sent online only.
- Notifications screen and a bell on Home; Home shows tasks to do, due today and unread.
- `fields.ts`: `visible()` and the resource type, the server's rules; the entry form hides fields by condition and picks resources from the kit.

**Verified.** logbook-app: Pint, PHPStan, lint, prettier, TypeScript, Vite build, Pest 110 passed (2 skipped) on SQLite. Phone: TypeScript, Expo lint, Jest 19 passed.

**Not verified.** PostgreSQL run (local server down; the CHECK constraints and partial index are PostgreSQL-only). Real email delivery (product mailers are `log` on prod). Anything on a device or in a browser by hand. No release, no deploy.

**Still deferred (§2.11).** Push notifications (needs a Firebase project and the owner's decision), QR scanning, related-entry links, imports, offline evidence photos.
