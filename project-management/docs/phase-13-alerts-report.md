# Phase 13: alerts, reminders and the daily email

People now hear when something needs them, and about what is late, by email as well as in the app. Before this, approvers had to think to open the approvals page, and nothing reminded anyone of a deadline.

## What was built

| Someone is told                                | Who                        | How                    |
| ---------------------------------------------- | -------------------------- | ---------------------- |
| Spending needs approval                        | `finance.expenses.approve` | App and email, at once |
| Spending is approved, rejected or paid         | Whoever requested it       | App and email, at once |
| Approved spending is ready to pay              | `finance.payments.record`  | App and email, at once |
| A budget needs approval                        | `finance.budgets.approve`  | App and email, at once |
| A budget is approved or returned               | Whoever submitted it       | App and email, at once |
| A task they lead is overdue                    | The task's lead            | App, then the digest   |
| A report to a funder is due in 7 days, or late | `grants.manage`            | App, then the digest   |
| A payment from a funder is late                | `grants.manage`            | App, then the digest   |

- **Daily digest:** `php artisan alerts:remind`, scheduled at 05:00, gives the reminders, then sends each person one email per organization listing what they haven't read. That includes mentions and task assignments, which stay out of immediate email.
- **Your account → Email alerts:** turns email off. Notifications in the app stay.

## Rules

- Nobody is told about what they did themselves. Whoever requested spending isn't asked to pay it.
- Who holds a permission is checked when the alert is written: suspended members and removed roles are left out.
- A reminder is given once per person for each due date. Moving the due date gives a new one.
- Emails are queued after the change commits, so a mail failure can't undo a payment and a refused change sends nothing.
- Only active users with a verified address get email.
- The digest skips anything read, already emailed, or more than 7 days old, so a first run doesn't send a backlog.
- Seeding the demo emails nobody, and its notifications stay out of the digest.

## Files

- `app/Actions/Alerts.php`, `app/Actions/Reminders.php`
- `app/Notifications/AlertEmail.php`, `app/Notifications/AlertDigest.php`
- `database/migrations/2026_11_05_001210_add_alerts.php`
- `routes/console.php`, `app/Actions/Spending.php`, `app/Actions/Budgets.php`
- `app/Http/Controllers/Work/NotificationController.php`, `app/Http/Controllers/Account/AccountSecurityController.php`
- `resources/js/pages/portal/account/settings.tsx`, `resources/js/pages/portal/notifications.tsx`
- `tests/Feature/AlertsTest.php`

## Needed in production

Both are part of the launch basics left for later:

- **The scheduler** (`php artisan schedule:run` every minute), or reminders and digests never run.
- **A queue worker** (`php artisan queue:work`), or queued emails never leave.

## Tested

- Automated, on SQLite, with email faked: who is told for each event, nothing on a refused change, email off, suspended holders, opening a notification, reminders once, digest contents, and separation between organizations.
- The whole suite, PHPStan and `tsc`.
- Migration up, down and up on SQLite.

## Not tested

- **Real email:** no message has actually been delivered, and their appearance hasn't been seen.
- **Real browser:** the Email alerts panel and the notifications page with the new kinds.
- **MariaDB:** the migration changes two columns to nullable that have foreign keys, so run it there before deploying.

## Known limits

- A second approver hears nothing extra: when spending needs two approvals, all approvers are told once, at the request.
- Rolling back the migration deletes notifications that open a page, because the old shape can't hold them.
- Reminders run at 05:00 UTC for every organization, whatever its timezone.
- Email is all or nothing per person; there are no settings per kind.
