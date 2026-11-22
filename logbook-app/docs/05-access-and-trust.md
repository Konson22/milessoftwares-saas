# 5. Access and trust

## Who can do what

Roles are given in the workspace's access settings, or by a Logbook manager on the People page.

| Role                  | Can                                                                                                                                                                                                                  |
| --------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Contributor           | Write and submit entries; correct returned ones; do the tasks given to them; report problems                                                                                                                         |
| Reviewer              | Review or return other people's entries, and read them with their history; give, follow up and verify tasks; assign and close exceptions                                                                             |
| Viewer                | Read entries and reports; changes nothing                                                                                                                                                                            |
| Auditor               | Read entries, reports and the audit trail; changes nothing                                                                                                                                                           |
| Manager               | Create logbooks and log sheets, publish templates, set schedules, keep resources, decide access, give and verify tasks, add people and give them Contributor, Reviewer or Viewer, read every logbook and its reports |
| Logbook administrator | Everything in Logbook, including the audit trail                                                                                                                                                                     |

Routes recorded on the phone: everyone sees their own. Others' routes need the permission
**View other people's recorded routes (GPS)** (`logbook.tracks.view`: managers, administrators and auditors) and access
to the logbook; restricted logbooks stay restricted. Exporting a route (GPX) and removing one
(managers) are recorded in the audit trail. Routes are removed after the retention time
(two years by default) once they end.

The workspace owner can do everything. Anyone with Logbook access sees and works on the tasks
given to them.

## Restricted logbooks

A manager can list who reaches a restricted logbook, for the whole logbook or one log sheet, as a
contributor, reviewer or viewer, and remove them again. A listing never gives anyone more than
their role allows. Records outside someone's reach do not exist for them: links to them answer
"not found".

Tasks and notifications follow the same rule: a task can only be given to someone who reaches
what it is about, and a notification only goes to someone who can open the record.

## People

Logbook managers can manage people without going to the workspace's access settings:

- **invite** someone by email, and cancel a pending invitation;
- **add an account** for someone directly;
- **give** and **take back** Logbook roles.

A Manager can give Contributor, Reviewer and Viewer. Manager, Auditor and Logbook administrator
are given by the workspace owner. The workspace's separation-of-duties rules still apply.

## Why you can trust the records

- **Nobody reviews their own entry,** verifies their own task, or closes an exception they
  resolved. This is enforced by the app and cannot be turned off.
- **Nothing is overwritten.** Each submission is kept as a separate version. When an entry is
  returned and corrected, the earlier version stays in its history. A review applies to the
  version the reviewer saw.
- **Old records keep their form.** A published log sheet version never changes, and each entry
  stays on the version it was written on.
- **Readings are recorded as they are.** A value outside the normal range is flagged and can open
  an exception, but it is never refused or changed.
- **Numbers without gaps.** References are given in order at first submission, per log sheet code
  and year.
- **Missed work is visible.** Scheduled log sheets show what was expected and not done, even when
  nobody wrote anything.
- **A permanent audit trail.** Every important action on logbooks, log sheets, entries, access and
  tasks is recorded with the person's name and the time. Audit events, versions and reviews
  cannot be edited or deleted, even directly in the database.
- **Each workspace's data is separate.** One organization can never see or link to another's
  records.
- **Files are private.** Photos and documents are served only to people who can open the record
  they belong to.
- **No hidden tracking.** Locations are taken only when the person presses the button, and
  routes only between the Start and End they press, with a phone notification the whole time.
  Gaps are shown as gaps, and distance is never counted across them.
