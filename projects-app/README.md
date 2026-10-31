# Projects

Project management for teams and the people who fund them: Laravel 13 + Inertia + React,
connected to the platform through
[`miles/platform-client`](../packages/platform-client/README.md). Served at
`{workspace}/projects`.

## Features

- **Portfolio**: every open project, worst health first, with open budgets against spend and
  overdue work across the workspace.
- **Projects**: a code (`WEB`) that numbers its tasks (`WEB-12`), a manager, dates, a budget,
  and a status (planning, active, on hold, completed, cancelled). The overview shows progress,
  milestones, what's due next, the top risks and an activity log.
- **Tasks** three ways: a Kanban **board** (drag across columns or within one to reorder), a
  **list**, and a **timeline** (Gantt bars from start to due, milestones, today). Each task has a
  priority, assignee, milestone, estimate, comments and the time logged on it.
- **Dependencies**: a task waits for others. It can't be marked done while they're open, and
  dependencies that would make a circle are refused.
- **Team**: people on the project with a role and a share of their time. Allocation across every
  open project shows who is overbooked. Milestones are reached or reopened.
- **Time**: members log their own hours (`1.5` or `1:30`). With `projects.time.manage`, anyone's.
  Each entry is costed at the person's hourly cost when it's logged. My work has a weekly
  timesheet.
- **Budget**: lines by category (labour, materials, equipment, travel, subcontract, other) against
  actuals. Labour actuals are time at cost; everything else is expenses. Once there are lines,
  the budget is their sum.
- **Risks**: a register scored probability × impact (1–25: 15+ high, 8+ medium), with owner,
  mitigation and review date.
- **Health** (`App\Support\ProjectMetrics`), for each project:
  - **Off track**: past its due date, or over budget.
  - **At risk**: progress more than 20 points behind the time elapsed, any overdue tasks, or
    spend more than 20 points ahead of progress.
  - Progress is estimate-weighted.

The platform has no member directory API, so the app keeps its own `people` table:
- **Members** are added on their first visit (`RememberPerson`). Their name and email come from
  the platform.
- **Contractors** are added by hand.

Amounts are integers in the currency's minor unit (`App\Support\Money`).

Not yet sent to Finance: project actuals stay in this app for now.

## Roles

Defined in `platform/catalog.json`:

| Role | Can do |
| --- | --- |
| Project Manager | Everything |
| Team Member | View, work tasks, log own time |
| Viewer | View only |
| Finance Controller | View, budgets and costs, anyone's time, people's costs |

## Develop

```bash
composer setup        # install, .env, key, migrate, build
composer dev          # php artisan serve on :8900, queue, Vite
composer ci:check     # format, lint, types, Pint, PHPStan, Pest
```

Register with the local platform:
`cd ../central-app && php artisan platform:local-product projects-app --url=http://127.0.0.1:8900 --entitle=<workspace-slug>`.
