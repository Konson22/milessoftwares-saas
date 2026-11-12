# Projects

Project management for NGOs, donors and the teams they fund: Laravel 13 + Inertia + React.
One of the platform's products (`miles/platform-client`): people sign in through the platform
(central SSO), each organization is a workspace on the platform, and members, roles and
invitations are managed there. Projects keeps its own database for its data and an audit log.

## Addresses

Projects is served in each workspace at `{workspace}.ROOT_DOMAIN/projects` (`PLATFORM_ROOT_DOMAIN`,
path `PLATFORM_PATH`, default `projects`). Every route carries the path itself.

- Signing in goes to the platform and back (`platform.login`). A session belongs to the one
  workspace it signed in to; another workspace is another sign-in (one click with SSO).
- The platform answers who the organization is (`platform.tenant`) and what the member may do here
  (`platform.access`). `App\Http\Middleware\EnterWorkspace` then keeps a copy of both: the
  organization (same id as on the platform) and the membership, with the permissions and roles it
  holds. Jobs, reminders and "who can approve this" read that copy (`App\Access\AccessResolver`).
- Organizations that fund others work in their own workspace too. An invitation to a beneficiary
  is emailed with a link to the donor's workspace (`/projects/invitations/{token}`); the person
  says which workspace their organization uses and accepts there (`partnerships/{token}`,
  `organization.manage`). An organization without a workspace starts one on the platform.
- At sign-up on the platform an organization says what it is: **donor or funder**, or
  **organization** (it runs the work). The form asks when Projects is among the trials, and the
  platform passes it on (`organization_type`). A donor has funding others switched on from its
  first visit. It stays a capability, not a fixed type: Administration → Settings turns it off or on.
- A donor can also **create** the workspace of an organization it funds (Beneficiaries → Create
  their workspace, `organization.manage`). The platform makes it and holds it, pending and with no
  owner, for the person named (`POST /api/v1/sponsored-organizations`, scope
  `organizations.sponsor`). It emails them a link, and claiming makes them its owner with the
  Projects trial on. The donor gets no place in that workspace. The partnership connects the first
  time its owner opens Projects (`Beneficiaries::connectCreated`) and shares nothing until they
  turn it on, grant by grant. Sending the link again works as for an invitation, and withdrawing
  an unclaimed workspace removes it.
- Each grant chooses what its donor sees: budget, spending, project progress and project tasks, all off
  until the beneficiary turns them on (`App\Support\DonorProjection`).
- `App\Tenancy\Hosts` builds absolute URLs to a workspace (scheme and port from `APP_URL`).
  Going from one workspace to another is a full page load.

## Features

- **Portfolio**: every open project, worst health first, with open budgets against spend and
  overdue work across the organization.
- **Projects**: a code (`WEB`) that numbers its tasks (`WEB-12`), a manager, dates, a budget,
  and a status (planning, active, on hold, completed, cancelled). The overview shows progress,
  milestones, what's due next, the top risks and an activity log.
- **Spaces**: where work is organized, Workspace → Space → Folder → List. Each list holds one
  project's tasks; every project gets a "Tasks" list in a folder of its own. Parts are archived,
  never deleted, once nothing open is left in them (`work.structure.manage`).
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
- **Budget** (`App\Actions\Budgets`): versions of lines by category (labour, materials,
  equipment, travel, subcontract, other). A draft is submitted, then approved by someone else
  (`finance.budgets.approve`) or returned with a reason. Approved versions never change: a
  revision is a new version, which supersedes the old one once approved, and it cannot cut a
  category below what is already committed or spent. The budget is the approved total, or the
  draft's until one is approved. By category: budget, committed, spent, available.
- **Spending** (`App\Actions\Spending`): requested (`projects.expenses.request`), approved
  (committed) by someone else (`finance.expenses.approve`) against what is left of the approved
  budget in its category and, when a grant is charged, of that grant's allocation to the project,
  then paid with a date and reference (`finance.payments.record`). Whoever requested it never
  approves or pays it. Rejected, cancelled and voided spending stays on record: nothing is
  deleted. Receipts and quotes are attached to the private disk and downloaded only through the
  app. A project manager can still record a payment already made in one step; it is marked
  "recorded directly" and is not checked against the budget. Labour is time at cost, never an
  expense. Actual cost counts paid spending only.
- **Approvals** (Finance → Approvals): budgets, requests and payments waiting, each for those who
  may act on it.
- **Risks**: a register scored probability × impact (1–25: 15+ high, 8+ medium), with owner,
  mitigation and review date.
- **Health** (`App\Support\ProjectMetrics`), for each project:
  - **Off track**: past its due date, or over budget.
  - **At risk**: progress more than 20 points behind the time elapsed, any overdue tasks, or
    spend more than 20 points ahead of progress.
  - Progress is estimate-weighted.

The team directory is the `people` table:
- **Members** are added on their first visit to an organization (`RememberPerson`). Their name
  and email come from their account.
- **Contractors** are added by hand.

Amounts are integers in the currency's minor unit (`App\Support\Money`).

## Grants and donors

Funding → Grants and Funders (`grants.view`; changes need `grants.manage`). Rules live in
`App\Actions\Grants`.

- **Funders**: the organization's own directory (institutional donors, UN agencies, governments,
  foundations…). Being a donor is a relationship, not an organization type.
- **Grants**: one record from opportunity → applied → active (awarded: the agreement) → closed,
  or declined.
  - Before the award, the amount is the request and can be edited.
  - Afterwards, the amount and end date change only by a numbered **amendment**. Amendments are
    never edited or deleted.
- **Allocations**: a grant funds several projects, and a project draws on several grants
  (no duplicate projects per donor).
  - Allocations never exceed the grant; the check runs on the locked grant row.
  - Across currencies, the rate is required (project currency per 1 unit of grant currency).
    The project's amount is computed exactly (bcmath, rounded half up) and stored with the rate.
- **Payments**: a schedule that never exceeds the grant. A received payment stays on record.
- **Reports to the funder**: due → submitted → accepted, or revision requested (owed again). A
  grant closes only when no report is owed.
- The project **Budget** page shows its funding by grant, what is committed and spent against
  each, and the part of the budget no grant covers. Spending charged to a grant holds its
  allocation (it cannot be removed or cut below the charges) and keeps the grant from closing
  while it is unpaid.

Every change is in the audit log.

## Members, roles and settings

- **Accounts, sign-in and two-step sign-in** are the platform's (central SSO). Users here are
  copies of the platform's, made on their first visit.
- **Members and roles**: invited, given roles, suspended and removed on the workspace's members
  page on the platform (`{workspace}.ROOT_DOMAIN/members`). A change shows here on the member's
  next request. Administration → Members lists who has opened Projects, with their roles, and is
  where each is placed in a department or team.
- **Permissions and roles** are declared in `platform/catalog.json` (application `projects`),
  which the platform imports on deploy. `App\Access\Catalog` reads the same file.
- **Funder contacts**: added on a grant's donor portal by email; the platform gives them a place
  in the workspace with the *Funder contact* role (`projects.donor.view` only). Their first visit
  makes them the funder's contact, kept to the donor portal.
- **Settings** (Administration → Settings): the parts of the product in use, funding others,
  project health slack, finance limits and spending approval bands. The organization's name,
  currency and time zone are the workspace's, changed on the platform.

| Role | Can do |
| --- | --- |
| Organization Administrator | Everything, plus approvals, grants, members' units, settings and the audit log |
| Project Manager | Everything on projects (drafts and submits budgets, requests spending), organizes spaces and lists; sees grants |
| Team Member | View, work tasks, log own time, request spending |
| Viewer | View only |
| Finance Controller | View, budgets and costs, people's costs; approves budgets and spending, records payments; manages funders and grants |
| Finance Officer | View, budgets and costs, grants; records payments of approved spending |
| Funder contact | The donor portal of their funder only |

Everything security-relevant is written to `audit_events` per organization (Administration →
Audit log). Rows are never updated or deleted by the app.

Console:

```bash
php artisan projects:demo <workspace>   # demo projects, grants, spending and team in a workspace
php artisan platform:outbox             # send events to Finance (scheduled every minute)
```

## Code layout

The app is grouped by business domain. Models stay in `app/Models` (the Laravel convention).

| Domain | Routes | Controllers | Domain code |
| --- | --- | --- | --- |
| Organizations and partnerships | `routes/web/partnerships.php` | `Organizations/` | `App\Actions\Organizations`, `App\Actions\Beneficiaries`, `App\Tenancy` |
| Administration | `routes/web/admin.php` | `Admin/` | `App\Access`, `App\Audit` |
| Projects | `routes/web/projects.php` | `Projects/` | `App\Support\ProjectMetrics`, `App\Support\ActivityLog` |
| Work (tasks, time) | `routes/web/work.php` | `Work/` | `App\Actions\Tasks`, `App\Actions\Timesheets` |
| Finance (budget versions, spending, approvals) | `routes/web/finance.php`, `approvals.php` | `Finance/` | `App\Actions\Budgets`, `App\Actions\Spending`, `App\Support\Money` |
| Petty cash and staff advances | `routes/web/petty-cash.php`, `advances.php` | `Finance/` | `App\Actions\PettyCash`, `App\Actions\StaffAdvances` |
| The books (Finance, the shared module) | | | `App\Actions\ProjectBooks`, `App\Support\FinanceEvents` |
| Funding (funders, grants) | `routes/web/funding.php` | `Funding/` | `App\Actions\Grants` |
| People | `routes/web/people.php` | `People/` | |

- `routes/web.php` wraps the domain files under the product's path, behind the platform's
  middleware and `EnterWorkspace`.
- Shared controller concerns live in `app/Http/Controllers/Concerns`.
- Pages are in `resources/js/pages/portal/<domain>/`.
- Typed route helpers (`@/routes`, `@/actions`) are generated by Wayfinder on build.

## Develop

```bash
composer setup        # install, .env, key, migrate, build
composer dev          # php artisan serve on :9300, queue, Vite
composer ci:check     # format, lint, types, Pint, PHPStan, Pest
```

Locally it runs behind the central app's Apache proxy (`ops/local-dev/apache-dev.conf` sends
`/projects/` to 127.0.0.1:9300). Register it with the platform, which writes the `PLATFORM_*`
settings to `.env` and gives a workspace the product:

```bash
cd ../central-app && php artisan platform:local-product project-management --url=http://127.0.0.1:9300 --entitle=<slug>
```

Then open `http://<slug>.milesoftwares.localhost/projects`. For demo data:
`php artisan projects:demo <slug>`.

The database is PostgreSQL: create a `projects` database and a user for it, and set `DB_*` in
`.env`. Tests run on in-memory SQLite with the platform faked (`InteractsWithPlatform`); CI runs
them on PostgreSQL. To do that here, on a separate database because it is emptied:
`DB_CONNECTION=pgsql DB_DATABASE=projects_test php artisan test`.
