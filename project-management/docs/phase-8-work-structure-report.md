# Phase 8: work structure, step 1 (Workspace → Space → Folder → List)

Item 4 of the first-phase plan in `platform-assessment-and-roadmap.md`.

## What changed

- **New tables:** `workspaces`, `spaces`, `folders` and `task_lists`. Every list sits in a
  space, and optionally in a folder of that space.
- **Nothing is deleted:** a part gets `archived_at` instead. The model is `TaskList`, because
  `List` is a reserved word in PHP.
- **`tasks.list_id`** is required. Migration `2026_11_05_000100_create_work_structure`:
    1. Creates the tables.
    2. Adds `list_id` as nullable.
    3. Backfills, in a transaction:
        - each organization with projects gets a "Main" workspace with a "Projects" space;
        - each project gets a folder ("CODE · Name") with a "Tasks" list holding all its tasks.
    4. Refuses to continue if any task is left without a list.
    5. Makes the column NOT NULL.
- **New projects** get the same automatically (`WorkStructure::setUpProject`). The workspace and
  space are created on first use.
- **Rules** (`App\Actions\WorkStructure`, every change audited as `work.*`):
    - A list holds one project's tasks. A task moves only between its own project's active lists,
      either from the task's edit form or by being added from a list page.
    - A task added without a list goes to the project's first active list.
    - Archiving needs the part to be empty of anything active:
        - a list with an open task can't be archived;
        - neither can a project's last list;
        - a folder needs its lists archived first, a space its folders and lists, a workspace its
          spaces.
    - Nothing can be added inside an archived part.
    - Archived lists keep their tasks, and the list page still opens.
- **Permission:** `work.structure.manage` ("Organize work"), given to Organization Administrator
  and Project Manager. Viewing needs `projects.view`.
- **Pages:**
    - Spaces (`/spaces`, in the sidebar): the tree, with open and total task counts per list.
      People who organize work can add, rename and archive parts there.
    - List page (`/lists/{id}`): the list's tasks grouped by status, its path, and an "Add a
      task" form.
    - Task page: shows its list, and the edit form can move it to another list.

## Tests and checks

- `tests/Feature/WorkStructureTest.php`: 6 tests, 176 assertions.
    - The default structure for new projects.
    - Creating, renaming and auditing every kind of part.
    - Adding to, and moving between, the project's own lists, and refusing other projects' lists.
    - The archiving rules.
    - Viewers can see the structure but not change it.
    - Another organization gets 404s and sees nothing.
- `composer ci:check` is green (91 tests, 1,607 assertions; lint, types, Pint, PHPStan), and
  `npm run build` succeeds.
- **MariaDB:**
    - The full suite passes (91 tests).
    - The migration was run on data shaped the old way (2 organizations, 3 projects, 12 tasks):
        - task counts per project were unchanged;
        - each project got one list, which belongs to that project;
        - no task was left without a list;
        - `list_id` became NOT NULL.
    - The migration was then rolled back and re-applied with the data kept.
    - Fixed on the way: MySQL lets the composite index serve the foreign key, so `down()` now
      drops the key before the index.
- **HTTP smoke test** (`php artisan serve` on MariaDB): sign in, create a project, then open
  `/spaces` (it shows "Main > Projects > SMK · Smoke project > Tasks") and the list page.
  Afterwards the dev database was reset (`migrate:fresh`, `access:sync`).
- **Not done:** a walkthrough in a real browser.

## How it differs from the roadmap, and what's next

- The roadmap proposed a space per project. Instead there's one shared "Projects" space per
  organization with a folder per project, which keeps the tree short. Structures can be
  reorganized freely afterwards.
- Every list still belongs to a project, because tasks are numbered (CODE-12) and authorized
  through their project. Work that belongs to no project, and spaces with their own members,
  need task numbering and authorization that don't depend on a project. That's step 2.
- Not yet built:
    - moving lists or folders to another space;
    - drag-to-reorder;
    - restoring archived parts;
    - per-list statuses;
    - board, calendar and table views per list (the list page groups by status; the project
      board still shows all of a project's tasks).
