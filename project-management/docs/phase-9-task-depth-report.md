# Phase 9: task depth (subtasks, collaborators, checklists, files)

The first items of "Do next" in `platform-assessment-and-roadmap.md`. Custom statuses per list, also
in that list, are left for their own increment: they change the board and every query on status.

## What changed

- **Subtasks:** tasks with a `parent_id`, one level deep, numbered like any task (WASH-11), in the
  parent's list and milestone.
    - A parent can't be done while a subtask is open.
    - A subtask can't reopen under a parent that is done, and nothing is added under a finished task.
    - A subtask stays in its parent's list and moves with it.
    - Boards, task lists, list pages and the timeline show top-level tasks, with subtasks counted on
      the card. Searching, or filtering by a person, finds subtasks too. My work shows them always.
    - Board order is kept among tasks of the same kind, so hidden subtasks don't shift where a card
      lands when dragged.
    - Project progress counts top-level tasks only, because a parent's estimate already stands for
      its subtasks. An overdue subtask still counts as overdue.
- **Collaborators (`task_collaborators`):** people on a task besides its lead. `assignee_id` stays
  the lead, so nothing that reads it changed meaning. Collaborators see the task in My work and on
  the home page, the person filter finds it, and it counts in their open tasks on the People and
  Team pages. Making a collaborator the lead takes them off the collaborator list.
- **Checklists (`task_checklist_items`):** steps to tick off, recording who ticked them. Unlike
  subtasks, they don't hold the task back.
- **Files (`task_attachments`):** on the private disk, served only through an authorized route, with
  the same file types and 10 MB limit as supporting documents on spending. Anyone who can view the
  project can download. Uploading needs `projects.tasks.manage`. Removing needs the uploader or
  `projects.manage`. Uploads and removals are audited.
- **Closed projects:** nothing inside a task changes, but files can still be downloaded.
- **Code:** rules in `App\Actions\Tasks`, routes in `TaskPartsController`, screens in
  `resources/js/components/task-parts.tsx`. Migration `2026_11_05_000200_add_task_depth` is purely
  additive. The demo data (`AsaasDemoSeeder`) includes all but files.

## Tests and checks

- `tests/Feature/TaskPartsTest.php`: 12 tests covering the rules above, viewer and worker
  authorization on every new route, parts reached through another task (404), another organization
  (404), and people from another organization (refused).
- Full suite on SQLite: 109 tests, 1,938 assertions. PHPStan, Pint, `tsc`, `vp check` and
  `npm run build` pass.
- **MariaDB:** the migration ran up, down and up again on the local database with its data kept.
- **HTTP smoke test** against the running dev server on MariaDB, signed in as a demo project manager
  (WASH-5): added a subtask, collaborator, checklist step and file; a `.exe` upload stored nothing;
  marking the parent done left it in progress; the file downloaded; the board, the subtask's page
  and My work answered 200. These records remain in the local demo data.
- **Not done:**
    - A walkthrough in a real browser (the Chrome extension wasn't connected), so the new panels and
      card counts have not been seen rendered.
    - The full suite on MariaDB: it empties the database, and the local one holds the demo data.

## Limits and next steps

- Subtasks and checklist steps can't be reordered by hand. Checklist steps can't be edited, only
  removed and added again.
- A subtask's own dates aren't kept inside its parent's dates.
- Time logged on a subtask counts towards the project, not its parent's logged total.
- Files aren't scanned for viruses.
- Next from the roadmap: custom statuses per list, then mentions with in-app notifications.
