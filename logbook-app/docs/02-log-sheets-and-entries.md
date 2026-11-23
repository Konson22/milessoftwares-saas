# 2. Log sheets and entries

## Logbooks

A logbook groups the log sheets for one area of work.

- A manager creates a logbook with a name and description, and edits it later.
- A logbook is **open** (every member with the right Logbook role reaches it) or **restricted**
  (only the people listed on it). See [Access and trust](05-access-and-trust.md).
- A logbook can be **archived** when it is no longer used and **restored** later. Archived
  logbooks take no new entries; their records stay readable.
- The logbook page lists its log sheets and, for managers, who has access.

## Building log sheets

A log sheet has a name, a short **code** (for example `GEN`) used in its reference numbers, and
a setting for whether its entries **need review**. `TASK` and `EXC` are reserved codes.

The form builder shows the fields on one side and a **live preview** on the other.

- Changes are kept as a **draft** until they are published. The draft can be saved and
  discarded.
- **Publishing** makes a new version. Entries already written stay on the version they were
  written on.
- A log sheet can be **duplicated** (to start a similar one), **archived** and **restored**.
- Any published log sheet can be put on a **schedule** (see [Operations](03-operations.md)).
- Confirmations (publish, discard, delete) open in a dialog inside the page.

### Location (GPS)

Each log sheet has a **Location (GPS)** setting, saved with the draft and published with it:

| Setting                  | What happens                                                                                       |
| ------------------------ | -------------------------------------------------------------------------------------------------- |
| No (default)             | No location is taken or kept. Any location sent is ignored.                                        |
| Where each entry is made | The entry form shows **Take my location**; the location is read once, when pressed.                |
| Record a route (trips)   | People record trips on the phone app; entries written on a trip are linked to it (see Operations). |

Options: whether each entry **needs a location** (or, for routes, a location or a trip), whether
a route **needs its vehicle or resource**, and an **accuracy** in metres to aim for. Entries keep
the setting of the version they were written on.

### Field types

| Type                           | Use                                                             |
| ------------------------------ | --------------------------------------------------------------- |
| Short text, Long text          | Names, identifiers, descriptions, remarks                       |
| Whole number, Decimal number   | Mileage, litres, running hours, readings                        |
| Date, Time, Date and time      | When something happened                                         |
| Single choice, Multiple choice | A status, a category, checklist items                           |
| Yes / No                       | Checks such as "Oil level OK?"                                  |
| File or photo                  | Pictures from the camera, PDFs, Word, Excel and other documents |
| Person                         | A member of the workspace, for example the operator or driver   |
| Resource                       | A generator, vehicle, room or other resource kept in Resources  |

### Field settings

- **Required or optional**, with help text and a default value.
- **Hard limits** on numbers: a value outside them is refused.
- **Normal range** on numbers, for example an expected temperature between 2 and 8 °C. A reading
  outside it is still recorded, because that is what the log is for, and is flagged as _out of
  range_ for the reviewer, on the entry and on the overview.
- **Unit** on numbers (km, litres, hours, °C), shown next to the value.
- **Show only when:** a field can be shown only for some answers of a yes/no or choice field
  above it, for example "Where is the leak?" only when "Leak found?" is Yes. A hidden field is
  not asked, is not required, and any answer it had is cleared when the entry is saved. The same
  rule applies on the web and on the phone.
- **Open an exception:** a number outside its normal range, or chosen answers of a yes/no or
  single choice field (such as "No" or "Faulty"), can open an exception automatically when the
  entry is submitted, with a severity.

Limits: up to 60 fields per log sheet; a file field takes up to 10 files of up to 10 MB each.

### Starter templates

The starters page shows each template as a card with a preview of its fields (types, choices,
required or optional, whether it needs review). Pick one, give it a name and code, and adjust
the copy.

- Daily Activity Log (`ACT`)
- Generator Operation Log (`GEN`): recorded per activity, hour meter and fuel
- Vehicle Movement Log (`VEH`): recorded per trip, odometer and fuel, route GPS
- Temperature Monitoring Log (`TMP`): scheduled; set the normal range before publishing
- Equipment Inspection Log (`INS`)
- Equipment Maintenance Log (`MNT`)
- Water Pump Operation Log (`PMP`): recorded per activity, hour meter
- General Incident Report (`INC`)
- IT Maintenance Log (`ITM`)

Each names its asset in one resource field (the *main asset*, ADR-0031). The builder's **Main
asset** panel chooses it when a sheet has several resource fields. A number field can say
whether its readings **add up** (litres, hours) or are **never totalled** (temperatures,
levels, pressures); by default the unit decides (°C, %, bar, psi, V… are never totalled).

## Entries

### Writing an entry

- Start from **New entry**, which lists the log sheets you can write on, grouped by logbook.
- Fill in the form. Required fields, limits and conditional fields are checked as you go.
- **Save as draft** to finish later, or **Submit**.
- Attach photos and files in file fields. They can be removed while the entry is a draft or
  returned.
- On log sheets that take a location, press **Take my location**. The browser asks for
  permission; the position, its accuracy and the time are shown before saving. Without it, no
  location is kept: none is ever guessed.
- A draft can be deleted. A submitted entry is never deleted.

### An entry's life

- **With review:** Draft → Submitted → Reviewed, or Submitted → Returned → (corrected) →
  Submitted → Reviewed.
- **Without review:** Draft → Recorded (finalized). A recorded entry is never shown as reviewed.

The reference number is given at first submission, in order per log sheet code and year (the
year in the workspace's own time zone).

### The entry page

- The answers, with units and out-of-range flags.
- Its **versions**: each submission is kept, so a corrected entry still shows what was first
  sent.
- Its **reviews**: who reviewed or returned it, when, and with what comment.
- Its **history**: every action on it from the audit trail.
- **Download as PDF.**
- Links to any exception it opened, and a button to **report a problem** by hand.
- Its **location**, if one was taken (position, accuracy, when, and whether by phone or
  browser), and a link to the route it was written on, for people allowed to see that route.

## Reviewing

- **Reviews** lists the entries waiting for you, in the logbooks you reach.
- Open an entry, then **mark it reviewed** or **return it** with a comment (a comment is
  required to return).
- A review is tied to the version you were shown. If the author resubmits while you are
  looking, your decision is refused and you see the new version first.
- Nobody can review their own entry.

## Reports and exports

The **Reports** page works within the logbooks you reach.

- **Filters:** logbook, log sheet, date range, person, status, review state and a search on
  reference or text.
- **Counts** by status, by day and by person.
- **Number fields:** average, lowest and highest of each, and a total only for readings that
  add up (fuel used, kilometres driven; never a total of temperatures).
- **Asset filters** (ADR-0031): asset, asset category, asset kind and location. An entry is
  about an asset when it is its main asset or when any of its resource fields names it.
- **By asset:** one row per asset: records, last record, readings out of range, open
  exceptions, expected checks on time, missed, fuel estimated. A count opens those records.
  With a log sheet chosen, its readings per asset: count, lowest, highest, average, total
  (only where readings add up) and out of range.
- **Scheduled compliance:** expected readings by log sheet and asset: on time, late, missed,
  excused, awaiting review, open, completion rate. Submission timeliness is kept apart from
  review: an entry submitted on time is on time however long its review takes.
- **Export** the filtered entries to **CSV** or **PDF**, and the asset and compliance reports
  to CSV or PDF. Every export says, at its head, the period and the filters it was made with.

## The web app's pages

| Page          | What it is for                                                                                                                                                         |
| ------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Overview      | Figures (logbooks, entries, pending review, reviewed, out of range, overdue tasks, missed entries, open exceptions), my tasks, due today, quick entry, recent activity |
| My entries    | Everything you have written, with its status                                                                                                                           |
| New entry     | The log sheets you can write on                                                                                                                                        |
| Logbooks      | The logbooks you reach, their log sheets, and who has access                                                                                                           |
| Log sheet     | The form builder with a live preview, publishing, schedules and the starter templates                                                                                  |
| Entry         | The form, or the submitted entry with its versions, reviews, full history and PDF                                                                                      |
| Reviews       | Entries waiting for you to review                                                                                                                                      |
| Tasks         | Work given to you, work you gave, all tasks and recurring tasks                                                                                                        |
| Schedules     | What is due, done on time, late or missed, with the completion rate                                                                                                    |
| Exceptions    | Problems being followed up, with their corrective tasks                                                                                                                |
| Resources     | Equipment, vehicles, rooms and their locations                                                                                                                         |
| Routes        | Trips recorded on phones, with filters, each on a map with its entries and gaps; GPX export                                                                            |
| Coming soon   | GPS devices and Fleet management: what is planned, nothing to connect yet                                                                                              |
| Reports       | Filtered counts, totals and exports                                                                                                                                    |
| Notifications | What needs you, and your email choices; a bell in the top bar shows the unread ones                                                                                    |
| People        | For Logbook managers: invite people and give them Logbook roles                                                                                                        |
| Audit trail   | Who did what, and when                                                                                                                                                 |

Pages and buttons you have no permission for are not shown.
