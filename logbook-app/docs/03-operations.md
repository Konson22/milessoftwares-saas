# 3. Operations

Operations is the work around the log: tasks people are given, log sheets that are expected at
set times, problems that need following up, and the notifications and reminders that keep them
moving, and, where it is switched on, the routes of trips. It is built and tested, and not yet
released to production.

## Tasks

A task is a piece of work given to one person.

**A task has:**

- a title, a description and a reference, for example `TASK-2026-000012`;
- an assignee, who must be able to reach the logbook, log sheet or entry the task is about;
- a **priority**: low, normal, high or urgent;
- an optional start time and **due time**;
- an optional logbook, log sheet, entry, resource or exception it relates to;
- **reminders** (see below);
- whether it needs **verification** by someone else when done.

**Its life:**

- Pending → In progress → Completed.
- With verification: In progress → Awaiting verification → Completed, or → Returned with a
  comment, then worked again.
- A task can be **cancelled** with a reason and **reopened**. Tasks are never deleted.
- A task is **overdue** when its due time has passed and it is still pending, in progress or
  returned.

**Doing a task:** the assignee starts it, then completes it with notes, **evidence** files or
photos (up to 10), the time it was done and, if useful, a link to the entry they wrote.

**Following up:** supervisors can reassign a task, change its dates, verify or return it,
cancel or reopen it. Nobody verifies their own task.

**The Tasks page** has four views: _Mine_ (given to me), _Given_ (given by me), _All_ and
_Recurring_ (the last two for those who give tasks). Each task page shows its work, its files,
its verification and a full history of every change (assignee, due date, status).

If two people change the same task at once (for example one reassigns it while the other
completes it), the second change is refused and shows the task as it is now. Nothing is
overwritten.

## Recurring tasks

A recurring task makes a new task on a pattern:

- **daily**, **weekly** on chosen weekdays, or **monthly** on a day of the month;
- with a start time, a due time, an assignee and reminders, in the workspace's time zone.

Each occurrence is created a day ahead as an ordinary task, in the time zone the recurring task
was set in. A monthly task on the 29th to the 31st falls on the last day of shorter months. A
time that a clock change skips moves forward; one that repeats takes the first.

The Recurring tab shows each one's state (**Active**, **Paused**, **Ended**) and when the next
is due. Managers can:

- **Pause**: no new tasks are made. Tasks not begun yet are withdrawn; those already begun stay
  to finish. Days while paused are never filled in later.
- **Resume**: tasks are made again from now on.
- **End**: final. Tasks not begun are withdrawn, nothing more is made, and it cannot be
  resumed. Everything already made stays in the history.
- **Change** it: the change applies to tasks not made yet. Tasks already made keep their
  person, times and reminders.

## Scheduled log sheets

A schedule can be paused, resumed and ended the same way as a recurring task. While paused no
expected entries are made, so paused days are never counted as missed. An expected entry
already open stays and is settled as usual.

Each expected entry keeps two things apart: **when the entry came in** (on time or late) and
**the review**. An entry submitted on time stays on time however long the review takes.

A manager can put any published log sheet on a **schedule**, so the app knows an entry is
expected even when nobody writes one.

**A schedule has:** daily, weekly or monthly; an opening time and a due time; an optional
responsible person; a **late window**; reminders; and a rule for when it counts as done: once
the entry is **submitted**, or only once it is **reviewed**.

**Each expected entry is:**

| Status    | When                                                        |
| --------- | ----------------------------------------------------------- |
| Open      | The time has come and no entry is in yet                    |
| Completed | An entry came in before the due time                        |
| Late      | An entry came in after the due time, within the late window |
| Missed    | Nothing came in by the end of the late window               |
| Excused   | A manager excused it, with a reason                         |

The first entry on the log sheet in that time (by the responsible person, when one is set)
counts. An entry waiting for review counts as submitted.

**The Schedules page** shows what is due, the completion rate, late and missed entries, and
those waiting on review. Managers excuse occurrences there.

## Exceptions

An exception is a problem to follow up, with a reference such as `EXC-2026-000003` and a
**severity**: low, medium, high or critical.

**How they are raised:**

- **Automatically**, when an entry is submitted with a reading outside its normal range or a
  failed check, on fields set to open an exception. One per entry and field.
- **By hand**, from an entry or on its own, by anyone who writes or reviews in that logbook.

**Its life:** Open → In progress → Resolved → Closed, and it can be reopened.

- The entry and its values are never changed by an exception.
- The fix is done through **corrective tasks** linked to the exception, with everything tasks
  have (assignee, due time, evidence, verification).
- Files and photos can be attached to an exception (up to 10).
- Supervisors assign exceptions and close them. Nobody closes an exception they resolved.

The **Exceptions page** lists them with filters; each one shows its entry, its corrective tasks
and its history.

## Notifications

Logbook tells people when something needs them, in four categories:

| Category   | For example                                                                                                     |
| ---------- | --------------------------------------------------------------------------------------------------------------- |
| Tasks      | A task given to you or taken off you, returned to you, due, overdue, waiting for your verification, or verified |
| Entries    | Your entry was returned or reviewed, an entry is waiting for your review, an entry has an abnormal reading      |
| Schedules  | An inspection is due, or was missed                                                                             |
| Exceptions | An exception was raised or assigned to you, or a reminder on one                                                |

- **In the app:** the Notifications page and a **bell** in the top bar with the unread count.
  Open a notification to go to the record; mark one or all as read; filter by category or
  unread.
- **By email:** important notices (a task given or returned, an overdue task, a missed
  inspection, an exception assigned) are also emailed. Each person turns email on or off per
  category on the Notifications page. Email is on by default.
- **On the phone:** the same notifications in the app, and, once phone notifications are
  switched on for the workspace, an alert on the phone while the app is closed. Each person
  turns phone notifications on or off per category on the Notifications page.
- Each email and phone notification is recorded with its attempts. One that fails is tried
  again after 1, 5, 15 and 60 minutes, then marked failed. A phone that no longer exists is
  forgotten.
- The same event never notifies the same person twice.
- A notification names the reference and title, never the values recorded.
- A person is only notified about records they can open, and only while they have access to
  Logbook.

## Reminders

Tasks, recurring tasks and schedules can have up to six reminders:

- at the start;
- 1 day, 2 hours or 30 minutes before the due time;
- at the due time;
- when it becomes overdue, or 1 hour after;
- or at a chosen date and time.

Reminders move when the dates change, go to whoever the task is assigned to at the time, and
are skipped when the work is already done or cancelled. Each is sent once.

**Send reminders by** chooses the channels for one task, recurring task or schedule. The
standard is a phone notification for every reminder and an email once the work is overdue.
Reminders always appear under the bell, and each person's own choices still apply.

A reminder for a scheduled log sheet opens that expected entry: the entry form says when it is
due, and the entry written is counted against it.

## Resources and locations

**Locations** are places, which can sit inside other locations (a site, a building, a room).
**Resources** (assets, ADR-0031) are the things people log against. Each has a code unique in
the workspace, a name, a category (its type), a location, and:

- a **kind**: a *physical asset* (a generator, a vehicle, an air conditioner) or a *monitored
  location* (a cold room or store that is checked; it must say where it is);
- a **status**: active (offered), inactive (out of service for now) or retired (history only);
- make, model, serial number, a responsible person, and **details** (rated power, tank
  capacity…), each with a label, a value and a unit.

**Registering.** Managers (`logbook.manage`) register assets on **Resources → Register an
asset**, starting from an **asset template**: seven starters (Generator, Vehicle, Cold Storage
Room, Air Conditioner, Refrigerator / Freezer, Water Pump, General Equipment) and the
workspace's own. A template sets the category, kind, the details to fill, an optional fuel rate
method, and the log sheets it suggests. The asset keeps a copy of the template as it was;
changing a template makes a new version and never changes assets already registered.

**Linking to log sheets.** A log sheet names its asset in one resource field, its *main asset*
(chosen in the builder; otherwise the fuel activity's resource field, else the only resource
field). Managers link assets to sheets from either side: one sheet can record many assets, and
one asset can be recorded on many sheets. Once a sheet has linked assets, only those are
offered on it (web and phone); with none linked, any active asset of the field's category is.
An asset's page can also create a log sheet for it from a suggested starter and link it.

**Per-asset schedules.** A schedule on a shared sheet can be for one asset: cold room 1 at 06,
14 and 22, cold room 2 at 08 and 20. Each asset's expected readings are its own: another
asset's entry never completes them. A schedule keeps its asset; unlinking waits for its
schedule to end.

**Every entry names its asset** (`log_entries.resource_id`, from the main asset field), and so
do exceptions raised from it and the corrective tasks that follow them. Retired assets keep all
of that; a draft naming an asset retired or unlinked since is refused on submit and shows why,
and is never moved to another asset.

The **asset page** shows the identity, the sheets that record it, its schedules, the last 30
days of expected checks, the latest records, missed readings, exceptions, tasks and
investigations, then fuel rates, meters and history.

- Everyone in Logbook can read assets; only managers register, change, link or retire them.
- This is not a finance asset register: there are no values, depreciation or stock.

## Routes

**Operations › Routes** lists routes recorded with the phone app, with filters by date, person,
vehicle, logbook, log sheet and status. A route opens on a map: the positions as recorded, the
start and last position, the entries written on it, and gaps (dashed red: no recording, not the
road travelled). Distance is an estimate from the recorded positions and is never counted across
a gap or a pause.

To switch it on for a log sheet: template › **Location (GPS)** › _Record a route_, then publish.
_Where each entry is made_ takes one location per entry instead. Off is the default.

**On each route:** distance (estimated), total and moving time, number of positions and any left
out as inaccurate, typical accuracy, gaps and why (no signal, the phone stopped the app, location
turned off, permission removed), the vehicle, the person, the log sheet, and the recording log
(started, paused, resumed, ended, interrupted).

- Routes are only recorded by the phone app, started and ended by the person. There is no
  automatic or hidden tracking.
- **GPX export** for anyone who can see the route; each export is recorded in the audit trail.
- Managers can **remove** a route (recorded in the audit trail). Entries written on it keep their
  own location.
- Routes are removed automatically two years after they end (the workspace can set another
  period).

**GPS devices** and **Fleet management** (under _Coming soon_) are descriptions of what is
planned; nothing can be connected yet.

## The clock behind it

Every minute the server runs one job that, for each workspace: makes the next day's recurring
tasks and expected entries, marks expected entries late or missed, sends the reminders that
are due, retries email and phone notifications that failed, and removes routes past their
retention. It is safe to run twice;
nothing is made or sent twice. If it did not run for a while (up to 7 days), the next run
makes what was missed, once.
