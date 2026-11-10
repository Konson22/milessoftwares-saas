# Phase 11: mentions and in-app notifications

People now hear about what happens on their tasks, inside the app. Nothing here sends email.

## What was built

- **Bell** in the top bar on every organization page, with the unread count for this organization.
- **Notifications page** at `/notifications`: newest 100, opening one marks it read and goes to its task, and "Mark all as read".
- **@mentions** in task comments: typing `@` offers people, and mentions are picked out in the comment.

| Someone is told when                                                            | Kind        |
| ------------------------------------------------------------------------------- | ----------- |
| A comment mentions them                                                         | `mentioned` |
| They become a task's lead (new task, subtask, or a change of lead)              | `assigned`  |
| They are added as a collaborator (the first time only)                          | `added`     |
| Someone comments on a task they lead or collaborate on, without mentioning them | `commented` |

## Rules

- Only people who could open the task are told: an active user, an active member of this organization, with `projects.view`. The same check decides who is offered after `@`.
- Nobody is told about what they did themselves.
- One comment gives one notification per person. A mention replaces the comment notification.
- Each person's notifications are their own, and each organization's stay in that organization. Opening someone else's gives 404.
- A mention must be the person's full name, in any case: `@Mary Akech`. `@Mary` alone mentions nobody unless someone is called exactly that, and `a@mary.org` never does. Choosing from the list inserts the full name.

## Files

- `database/migrations/2026_11_05_000400_create_user_notifications.php`
- `app/Models/UserNotification.php`, `app/Actions/Inbox.php`
- `app/Actions/Tasks.php` (`addComment`, `tellAssignee`, collaborators), `app/Http/Controllers/Work/TaskController.php`
- `app/Http/Controllers/Work/NotificationController.php`, `routes/web/projects.php`
- `resources/js/pages/portal/notifications.tsx`, `resources/js/components/mention-textarea.tsx`, `resources/js/layouts/product-layout.tsx`
- `database/seeders/AsaasDemoSeeder.php`: demo comments go through the same rule and include mentions
- `tests/Feature/NotificationsTest.php`

## Tested

- Automated: mention matching, each kind, no self-notification, one notification per person, recipients without access excluded (no permission, suspended member, suspended user, no login, another organization), no mail sent, unread count, open, mark all read, another person's and another organization's notifications, signed-out and non-member access.
- Migration up, down and up on MariaDB.
- HTTP against the dev server: comment, recipient's unread count and list, open and redirect, count back to zero, another member gets 404.

## Not tested

- **Real browser:** the bell's appearance, the `@` list (typing, arrow keys, Enter, mouse) and the mention highlighting have not been seen in a browser. They pass type checks and lint only, and there is no automated test of the `@` list.
- Volume: the unread count runs on every page and is indexed, but has not been measured with a large table.

## Known limits

- The bell updates when a page loads, not live.
- No email or digest, and no per-person settings to turn kinds off.
- Status changes and approvals don't notify anyone yet.
- Mentions work in task comments only, and editing a task's description doesn't notify.
- A notification keeps the text it was written with: renaming a task or person doesn't change old ones.
- If someone loses access later, old notifications stay in their list, but opening the task follows the normal permission check.
