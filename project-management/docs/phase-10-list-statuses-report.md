# Phase 10: a list's own statuses

The next item of "Do next" in `platform-assessment-and-roadmap.md`.

## What changed

- **`list_statuses`:** a list can name the steps of its work ("Drilling", "Quality check"). Each name
  sits inside one of the four fixed categories: to do, in progress, in review, done.
- **`tasks.status` stays the category,** and `tasks.list_status_id` holds the name. So progress,
  health, reports, My work and the rule that a task can't be done while something holds it back
  don't depend on what a list calls things. A list with no statuses behaves as before.
- **Moving a task:**
    - To a name: the name's category becomes its status, through the same checks as any move.
    - By category, as from the board: the name stays if the category does. Otherwise the task takes
      the list's first name for that category, or none.
    - To another list, with its subtasks: each keeps its category and takes the new list's first
      name for it, or none.
- **New tasks** take the list's first name for their category.
- **Managing statuses (`work.structure.manage`, on the list page):** add, rename, reorder within a
  category, remove. All audited except reordering.
    - A status with tasks can't change category, because that would move tasks past the done rules.
      Add a new status instead, or move the tasks first.
    - Removing a status keeps its tasks and their category; they just lose the name.
    - An archived list's statuses don't change.
- **Screens:**
    - The list page groups tasks by name, in category order. Tasks with no name appear under the
      plain category.
    - The task page's status picker offers the list's names.
    - The project board keeps its four columns, because a project's lists can name things
      differently. Its cards show the name.
- The demo data (`AsaasDemoSeeder`) names the steps of WASH's list.

## Tests and checks

- `tests/Feature/ListStatusesTest.php`: 12 tests covering the rules above, authorization on every
  route, another list's status (refused or 404), another organization (404), and lists without
  statuses staying as they were.
- Full suite on SQLite: 121 tests, 2,113 assertions. PHPStan, Pint, `tsc`, `vp check` and
  `npm run build` pass.
- **MariaDB:** the migration ran up, down and up again on the local database with its data kept.
- **HTTP smoke test** against the running dev server on MariaDB, signed in as a demo project manager:
  added four statuses to WASH's list, a duplicate name was refused, and WASH-5 moved to "Quality
  check", which made its status "in review" on the task page, the board and the list page. These
  records remain in the local demo data. The other WASH tasks there have no names yet, so they show
  under the plain categories; a fresh seed gives them names.
- **Not done:** a walkthrough in a real browser (the Chrome extension wasn't connected), and the
  full suite on MariaDB, which would empty the local database.

## Limits and next steps

- Dragging on the project board moves between categories only. There is no board per list yet.
- Statuses have no colours, and reordering uses up and down buttons rather than dragging.
- A subtask moving with its parent can lose its name if the new list has none for its category.
- Next from the roadmap: mentions with in-app notifications.
