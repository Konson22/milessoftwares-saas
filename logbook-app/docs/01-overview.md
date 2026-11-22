# 1. Overview

## What Miles Logbook is

Miles Logbook is a digital logbook. It replaces paper log books and Excel registers with records
that have a fixed structure, a reference number, a supervisor's review and a history that nobody
can edit. It also runs the work around the log: tasks, inspections that are due, problems that
need following up, reminders and, where it is switched on, the location of entries and the
routes of trips recorded on the phone.

_Every shift, on the record._

It has two parts:

- **The web app**, used in a browser at `{workspace}.milessoftwares.com/logbook`. Managers set up
  logbooks, log sheets, schedules and resources there, and everyone can write, review, follow up
  work, report and export.
- **The phone app** (Android), for writing entries, doing tasks and recording trips in the
  field, with or without a connection, and for reviewing entries on the go.

You sign in with your Miles Softwares account, the same one you use for the other Miles
applications, or with Google. Logbook keeps no passwords of its own.

## Who it is for

Organizations that keep operational logs, for example:

- **Facilities and estates:** generator running hours, fuel, cleaning and inspection rounds.
- **Fleet and transport:** vehicle movements, drivers, mileage, and the route of each trip.
- **IT teams:** maintenance, incidents, equipment checks.
- **Security and front desks:** daily occurrence books, visitor and key logs.
- **Cold chain and health:** fridge temperatures with a normal range, daily checks.
- **Field offices and NGOs:** daily activity records with photos and documents.

## The main ideas

| Term             | What it means                                                                                                                                                                  |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Logbook**      | A folder for one area of work, for example "Main site generators" or "Fleet". It is either open to every member with the right role, or restricted to the people listed on it. |
| **Log sheet**    | The form people fill in, inside a logbook. A manager builds it from fields and publishes it.                                                                                   |
| **Version**      | Each time a log sheet is published, it becomes a new version. Entries already written keep the version they were written on, so changing a form never changes old records.     |
| **Entry**        | One filled-in log sheet: what happened, when, by whom, with any photos or files.                                                                                               |
| **Reference**    | The number an entry gets when it is first submitted, for example `GEN-2026-000042`. Drafts have no number, so the numbers have no gaps. Tasks and exceptions have their own.   |
| **Review**       | A second person checks the entry and marks it reviewed, or returns it with a comment for correction.                                                                           |
| **Task**         | A piece of work given to a person, with a due date, priority, reminders and, when needed, verification by someone else. It can repeat daily, weekly or monthly.                |
| **Schedule**     | A log sheet that is expected to be filled in at set times, for example the generator log every morning. Each expected entry is marked done on time, late, missed or excused.   |
| **Exception**    | A problem to follow up: a reading out of its normal range, a failed check, or something reported by hand. It is fixed by corrective tasks and closed by someone else.          |
| **Resource**     | A generator, vehicle, room or other item, kept at a location, that entries and tasks refer to.                                                                                 |
| **Location**     | Where an entry was made, taken by the phone or browser when the person asks, on log sheets set to take one. Off by default.                                                    |
| **Route (trip)** | The positions a phone recorded during a trip the person started and ended, shown on a map with the entries written on the way, on log sheets set to record routes.             |
| **Notification** | A message in the app (and, if wanted, by email) when something needs you: a task given, an entry returned, an inspection missed.                                               |

## How it works

1. **Set up.** A manager creates a logbook and a log sheet. They build the form from thirteen
   field types, or start from a ready-made template, and publish it. They can set it on a
   schedule and add the resources (equipment, vehicles, rooms) people will pick.
2. **Record.** Staff fill in the log sheet on the web or on their phone, and attach photos or
   documents. They can save a draft and finish it later.
3. **Review.** If the log sheet needs review, a supervisor marks the entry reviewed or returns it
   with a comment. The author corrects a returned entry and submits it again.
4. **Follow up.** Out-of-range readings and failed checks open exceptions. Supervisors give
   corrective tasks, people do them, and someone else verifies and closes them. Missed
   inspections and overdue tasks show on the overview and are notified.
5. **Report.** Managers and viewers filter the entries, see counts and totals, and export them to
   CSV or PDF. Recorded routes are reviewed on a map and can be exported as GPX.

## The documentation

| Page                                                      | What it covers                                                                                        |
| --------------------------------------------------------- | ----------------------------------------------------------------------------------------------------- |
| [1. Overview](01-overview.md)                             | This page: what Logbook is, the main ideas, availability                                              |
| [2. Log sheets and entries](02-log-sheets-and-entries.md) | Logbooks, the form builder and its field types, entries, review, reports and exports, the web pages   |
| [3. Operations](03-operations.md)                         | Tasks, recurring tasks, scheduled log sheets, exceptions, notifications, reminders, resources, routes |
| [4. The phone app](04-phone-app.md)                       | The Android app: its screens, working offline, tasks and trips on the phone, security                 |
| [5. Access and trust](05-access-and-trust.md)             | Roles, restricted logbooks, People, who sees routes, and why the records can be trusted               |
| [Features for customers](miles-logbook-features.md)       | One page to share with customers: everything Logbook does, in plain words                             |

## Availability

- Miles Logbook is in **Early access**.
- Plan: **Logbook Standard**, USD 15 a month per workspace (still to be created in admin on
  production).
- The web app is live at `{workspace}.milessoftwares.com/logbook` for entitled workspaces, with
  logbooks, log sheets, entries, review, reports, exports and People.
- Built and tested but **not yet released** to production:
    - operations: tasks, recurring tasks, schedules, exceptions, notifications, reminders,
      resources and conditional fields;
    - pausing, resuming and ending recurring work, and email/phone delivery with retries;
    - optional GPS: entry locations, trips recorded on the phone and the Routes map.
- The Android app (package `com.tatook.logbook`) has test builds and is being prepared for
  Google Play. Trips need a new build (location permission) and have not yet been tried on a
  phone.
- Emails are sent once Logbook's mail sender is set up on production; until then notifications
  appear in the app only.
- **Coming soon** (pages in the app describe them; nothing can be connected yet):
    - GPS devices installed in vehicles and equipment, and tracking platforms such as Traccar;
    - fleet management: live vehicle locations, movement history, geofencing, trip analytics.
- Not yet available:
    - push notifications on the phone: built, switched off until the workspace's Firebase project
      exists (the phone shows the same notifications when it is opened or synced);
    - a map on the phone (routes are seen on the web);
    - recording trips on iPhone;
    - scanning a QR code on a resource;
    - linking related entries to each other;
    - importing old records;
    - evidence photos on tasks while offline;
    - editing an entry after it is recorded (a correction is a new entry that refers to the old
      one);
    - links to other Miles apps such as Projects or HR.

## More detail

- Decision records: [ADR-0025](../../docs/adr/0025-tatook-logbook.md) (Logbook),
  [ADR-0026](../../docs/adr/0026-logbook-operations.md) (operations),
  [ADR-0027](../../docs/adr/0027-logbook-recurrence-and-delivery.md) (recurring work, delivery,
  phone notifications) and [ADR-0028](../../docs/adr/0028-logbook-gps-routes.md) (GPS and routes)
- Running and deploying: [runbook](../../docs/runbooks/logbook-app.md)
- Phone app internals: [logbook-mobile-app/README.md](../../logbook-mobile-app/README.md)
