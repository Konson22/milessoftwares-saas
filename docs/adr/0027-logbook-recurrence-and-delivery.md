# ADR-0027: Miles Logbook: recurring work lifecycle, reminder delivery and phone notifications

Status: **Accepted (2026-11-20). Built locally, uncommitted, not deployed. Push switched off until Firebase exists.**
Extends ADR-0026 (Logbook operations). Same app (`logbook-app`), same phone app (`logbook-mobile-app`), same database. No new engine: the task series, schedules, reminders and notifications of ADR-0026 stay. This ADR fills the gaps found when they were inspected.

## 1. What already existed (ADR-0026)

- Task series (daily, weekly on days, monthly on a day, last-day clamp) made a day ahead by `logbook:operations` every minute, unique `(series_id, occurrence_on)`.
- Schedules with durable expected entries (`log_schedule_occurrences`), unique `(schedule_id, occurs_on)`, settled to completed, late, missed or excused.
- Reminder rows (`log_reminders`) with presets before, at and after the due time, moved on reschedule and cancelled on completion.
- In-app notifications deduplicated per recipient, with email for important notices.
- Phone task outbox with keyed completion and explicit 409 refusals.

## 2. Gaps and decisions

| # | Gap | Decision |
|---|---|---|
| 1 | Only an `active` flag: no pause, resume or end, and resuming filled in every paused day. | `paused_at`, `ended_at` and `generate_from` on series and schedules. Nothing is made for a date due before `generate_from`, which resume and edits move to now. End is final (resume refused). Pause and end withdraw only work not yet begun (tasks not started; expected entries not open and unanswered). Begun work and history stay. |
| 2 | An outage longer than a day lost dates; two runs could race. | Generation looks back `catch_up_days` (7) and skips dates already made in one query. The unique keys stay the last word. |
| 3 | Email was queued directly: no record of what was sent, no retries. | `log_notification_deliveries`: one row per notification and channel (unique), status pending/sent/failed/skipped, attempts, `next_attempt_at`, last error, push tickets. Backoff 1, 5, 15, 60 minutes, then failed. A sweep in `logbook:operations` resends what a lost job left. The in-app notice is the record; deliveries are separate. |
| 4 | No phone notifications. | Expo push service (`ExpoPush`) behind `LOGBOOK_PUSH_ENABLED` (off by default). `log_push_devices`: one token per install, owned by one user, moved when another person signs in on the phone, removed on sign-out, revoked on `DeviceNotRegistered` from tickets or receipts. |
| 5 | Every reminder went to every channel. | `reminder_channels` on tasks, series and schedules. Null is the standard (phone for each reminder, plus email once overdue). Each person's preferences (`email`, `push` per category) still apply. In-app is always on. |
| 6 | A reminder opened a blank entry; the server guessed which expected entry it answered. | The reminder links `entries/new?sheet=…&occurrence=…`. The entry stores `schedule_occurrence_id` (same sheet only), and matching tries it first. The phone keeps the occurrence id on the draft and sends it. |
| 7 | Review state and timeliness were one status. | `timeliness` (on_time or late) is set when the entry comes in, apart from the review. An entry in on time stays on time however long the review takes. |
| 8 | An offline completion only had the phone's time. | `completion_received_at` beside `completed_at`. |
| 9 | Push content and routing. | The title and body are the notice's own (record names and times, no field values). Data carries `notificationId`, `userId`, `workspace` and a `route` (`task`, `review`, `entry`, or `record` with sheet and occurrence). The phone opens a push only for the person and workspace signed in; the screen fetches the record again, so access is checked by the server. |
| 10 | PostgreSQL session zone. | `timezone => UTC` on the pgsql connection, as Central already has. Without it a server outside UTC shifted every stored time. |

## 3. Time

Dates are made in the series' or schedule's own time zone (`Recurrence`), then stored in UTC. A local time that does not exist (DST gap) moves forward; one that repeats takes the first. Month days past the end clamp to the last day. The server's clock decides; the phone's clock is only the reported time of offline work.

## 4. Not done

- No Firebase project yet: push stays off. See the phone app docs for what the owner must create.
- iOS push is not configured (no APNs key).
- Reminders are not scheduled on the phone; a phone that is off gets them when it is back on.
