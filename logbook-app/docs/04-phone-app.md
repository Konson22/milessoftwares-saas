# 4. The phone app

**Miles Logbook** for Android (package `com.tatook.logbook`) is for the people who write the log,
do the work and review it. Setting up logbooks, log sheets, schedules, resources, access,
reports and the audit trail stays on the web.

## Signing in

- Sign in through Miles Softwares, with your account or with **Google**.
- If you belong to more than one workspace, you **choose the business** to work in.
- The **Account** screen shows who you are, the workspace and your Logbook roles, and lets you
  sign out. If you have no Logbook role yet, it says so.

## Tabs

- **Home:** a **Trip** card on workspaces with log sheets that record routes (start a trip, or
  see the one recording), what needs you (returned entries, entries that could not be sent), tasks to do,
  inspections due today, drafts, entries waiting to send, entries awaiting your review, unread
  notifications (with a bell), the connection and the last sync.
- **Entries:** your entries on this phone, filtered by _needs you_, drafts, sending, in review,
  done, or all; search by reference or log sheet.
- **New:** the log sheets you write on, grouped by logbook.
- **Tasks:** inspections due today, your open tasks and recent ones.

## Menu

The menu opens from the button at the top left, or by swiping in from the left edge. It shows
who you are and the workspace.

- **Home:** the tabs above.
- **Reviews:** entries waiting for you (only if you review); mark them reviewed or return them
  with a comment.
- **Sync:** sync now, retry what failed, update log sheets, and see what has not been sent.
- **Notifications** and **Account**.

Other screens, opened on top of the menu: an entry, a review, a task and a trip.

## Writing entries

- The form follows the log sheet exactly: the same field types, required fields, limits,
  units and conditional fields as the web, checked by the same rules.
- Readings outside the normal range are warned about before sending, and still recorded.
- **Photos** from the camera or gallery, and **files** from the phone, in file fields.
- **Person** and **Resource** fields pick from the workspace's people and resources, downloaded
  with the log sheets. On a sheet with linked assets (ADR-0031), its main asset field offers
  only those, offline too; the expected readings offered are the chosen asset's and the
  whole sheet's.
- A draft naming an asset retired or unlinked since it was written says so, keeps its choice
  and is not sent until another asset is chosen. The phone never swaps it for another.
- **Location:** on log sheets that take one, **Take location** reads the phone's position once,
  with its accuracy, and warns when it is less accurate than the sheet asks. During a trip,
  the entry can be linked to it instead.

## Tasks on the phone

- Open a task to see what is asked, its due time, priority and what it relates to.
- **Start** and **complete** a task, with notes, even with no connection. The work is sent with
  the time it was actually done.
- **Evidence photos** need a connection.
- If the task changed meanwhile (reassigned, cancelled, already completed), the phone shows why
  it was refused and keeps it until you dismiss it.

## Working offline

- Log sheets, people and resources are downloaded to the phone, so entries can be written with
  no connection.
- An entry is saved on the phone as it is typed. **Finish and send** checks it with the same
  rules as the server and puts it in the queue.
- The queue sends entries, files and task work as soon as there is a connection, and retries by
  itself when the network drops. Nothing is counted as sent until the server confirms it, and
  the same entry is never created twice.
- An entry that the server refuses (for example because its log sheet was closed) shows under
  _Needs you_, with the reason.
- An entry returned by a reviewer comes back to the phone with the reviewer's comment, ready to
  correct.
- Reviewing needs a connection, so that the reviewer always sees the latest version.

## Trips and routes

Only on log sheets set to record a route (template › Location), and only when you start one.

- **Trip** on the home screen: choose the log sheet and the vehicle, read what is recorded, tick
  _I understand_, then **Start trip**. Android shows a notification the whole time it records.
- While it records, the phone reads its location every few seconds, also with the screen off.
  **Pause** and **End trip** stop it; nothing is read while paused.
- Positions are kept on the phone (encrypted) and sent in batches when there is a connection.
  Nothing is removed from the phone until the server confirms it has it.
- If the phone closes the app during a trip, the next time it opens the trip shows as
  _Interrupted_: resume it or end it. It never starts again by itself. The route shows a gap for
  the time nothing was recorded.
- Entries written during a trip are linked to it (untick to leave one out). A sheet set to take
  a location shows **Take location**: the phone reads it once, when pressed.
- The trip screen shows the time, the estimated distance, the number of positions, the age and
  accuracy of the last one, and what is still to send, with warnings when location is turned
  off, permission is removed or no position has come for two minutes.
- **Earlier trips** lists the trips on this phone and whether each was sent. Ended trips the
  workspace has received leave the phone after 30 days.
- The app asks only for location _while in use_; it never asks for background location.

## Two separate statuses

The app shows two things separately:

- **Sending:** whether the server has the entry yet (Draft, Waiting to send, Sending, Sent,
  Needs you).
- **Review:** what the reviewer decided (Awaiting review, Returned, Reviewed, Recorded).

Reaching the server is never shown as reviewed.

## Notifications

The Notifications screen shows the same notifications as the web: tasks given, work due,
entries returned, inspections missed, exceptions. Tap one to open it; mark all as read. They
are refreshed when the app opens and on each sync.

**Phone notifications** (alerts while the app is closed): after signing in, the app asks once
for permission and registers the phone for the person signed in. Tapping an alert opens what
it is about: a task, an entry to review, your entry, or the log sheet of an expected entry
(opening the draft already begun for it, if any). An alert sent to someone else who used the
phone before opens nothing. Signing out stops the phone's notifications and clears those
shown. Reminders are timed on the server; nothing runs in the background on the phone.

Phone notifications need, once, from the workspace owner:

1. A Firebase project with an Android app for the package `com.tatook.logbook`, and its
   `google-services.json` given to the build (EAS file variable `GOOGLE_SERVICES_JSON`).
2. The project's FCM V1 service-account key uploaded to EAS (`eas credentials`, Android,
   push notifications).
3. On the server, `LOGBOOK_PUSH_ENABLED=true`, and optionally `LOGBOOK_EXPO_ACCESS_TOKEN` when
   push security is turned on in the Expo account.
4. A new build of the app (the notifications module is native).

The home screen also shows expected entries missed this week, work that could not be sent,
and, for reviewers, entries waiting for review.

## Security on the phone

- Entries and task work are stored in an encrypted database. Its key is kept in the Android
  Keystore.
- Each person's work is kept apart, so a shared phone shows nobody else's.
- Photos are re-encoded before upload, which removes their location and camera data.
- Trip positions are kept in the same encrypted database, apart for each person and workspace.
  Signing out or switching workspace stops a trip that is recording; it stays for its owner.
- Signing out never deletes work that has not been sent. It stays encrypted and is sent at the
  next sign-in. If anything is unsent, the app asks you to confirm by typing `SIGN OUT`.
- The phone uses the same permissions as the web: it can only reach the logbooks, log sheets and
  tasks you can.
