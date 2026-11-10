# Platform assessment and roadmap

Date: 2026-11-04. Answers the "Immediate instruction" of the master implementation prompt (ten
items). It is written against the code **as it is today**, after the work in
`docs/phase-1-audit.md` through `docs/phase-6b-finance-report.md`. The original pre-change audit
(SSO, central platform, original features) is `docs/phase-1-audit.md`; it is summarized here, not
repeated.

Everything below was checked in the repository (code, migrations, config, routes, tests).
Nothing is assumed.

---

## 1. Existing architecture assessment

| Area            | Today                                                                                                                                                                                                                                               |
| --------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Backend         | Laravel 13, PHP 8.4 (bcmath required). Modular monolith: domain folders under `app/Actions`, `app/Http/Controllers/{Organizations,Account,Admin,Projects,Work,Finance,Funding,People,Guest}`, `routes/web/*.php`                                    |
| Frontend        | Inertia 3 + React 19 + TypeScript, Tailwind 4, Vite 8 (vite-plus). UI kit vendored in `resources/js/ui` (`@miles/ui`). Typed routes by Wayfinder. Pages: `resources/js/pages/guest` (public site), `resources/js/pages/portal` (product)            |
| API style       | Server-driven Inertia pages and form posts. No JSON/REST API, no mobile API                                                                                                                                                                         |
| State           | Inertia props and shared props only. No client store; nothing sensitive in localStorage                                                                                                                                                             |
| Database        | PostgreSQL, dedicated database `projects`. Eloquent ORM. 9 migrations, UUID keys, foreign keys, `organization_id` on every tenant table. SQLite in-memory for tests (also run on PostgreSQL)                                                        |
| Authentication  | Laravel Fortify. Argon2id, HTTP-only encrypted session cookie, SameSite=Lax, CSRF. Five failed sign-ins a minute per address and IP. Email verification, password reset and change, invitations with one-time activation links (hashed tokens)      |
| Authorization   | Permission catalog `config/access.php` → `php artisan access:sync`. Roles per organization membership. `Gate::before` reads the member's access; checks on routes (`can:`) **and** inside domain actions. Organization owner holds every permission |
| Tenancy         | Organization per subdomain (`{slug}.APP_DOMAIN`, `App\Tenancy\Hosts`, `ResolveOrganization`). `BelongsToOrganization` + `OrganizationScope` fail closed when no organization is bound. Scoped route bindings for nested records                     |
| Public site     | Main domain: `pages/guest` (home, features). Workspace pages answer 404 there; the site answers 404 on subdomains                                                                                                                                   |
| Audit           | `audit_events`, append-only, per organization and per account, with an admin viewer                                                                                                                                                                 |
| Background jobs | Database queue (mail only). No scheduled tasks                                                                                                                                                                                                      |
| File storage    | Private `local` disk (expense documents), served only through authorized routes                                                                                                                                                                     |
| Integrations    | SMTP (Mailtrap in development). Nothing else. `IndependenceTest` fails on any stray HTTP call                                                                                                                                                       |
| Quality gates   | `composer ci:check` (format, lint, types, Pint, PHPStan, Pest). 69 tests, 1,039 assertions, passing on SQLite and PostgreSQL                                                                                                                        |
| Deployment      | **None in the repository**: no Dockerfile, CI workflow or server config                                                                                                                                                                             |

### Technical debt and risks found

1. **No version control.** The repository is not a git repository; rollback relies on a tar
   snapshot. This is the largest engineering risk. Recommend `git init` before the next phase.
2. **No deployment or CI configuration.** The checks only run locally.
3. **No browser-level tests.** Pages are type-checked and built, and their server props are
   tested, but no click-through tests exist (for example Playwright or Dusk).
4. **Hardcoded policy thresholds.** Project health uses fixed 20-point rules
   (`App\Support\ProjectMetrics`). The brief says utilization thresholds must be configurable per
   organization or donor.
5. **Fixed task workflow.** Task statuses are a fixed four (`todo`, `in_progress`, `review`,
   `done`). Custom statuses need a model.
6. **Single assignee** per task (`tasks.assignee_id`).
7. **Wayfinder and domain routes.** A domain-bound route's generated URL drops the port, so the
   public pages are linked by plain paths (documented in `routes/web/guest.php`).
8. **Approvals queue cost.** One availability query per request row, up to 200 rows, no paging.
9. **Documents are on local disk** with no virus scanning. Production needs private object
   storage.

---

## 2. Feature inventory

Classification: **Done** (fully implemented and tested), **Partial**, **UI-only**, **Missing**,
**Duplicated**, **Refactor** (exists but needs reshaping for the target). No feature was found to
be UI-only or duplicated.

| Domain                 | Status                | What exists                                                                                                                                                             | What the brief adds                                                                                                                         |
| ---------------------- | --------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| Authentication         | Done / Partial        | Registration, login, logout, verification, reset, change, invitations, activation, admin suspension, console status, other sessions signed out on password change       | MFA (Missing), session list and revoke (Missing), self-service deactivation (Missing)                                                       |
| Organizations          | Done                  | Create (open or admin-only), settings, members, roles, invitations, suspension, audit log, subdomain per organization                                                   | Cross-organization relationships (Missing)                                                                                                  |
| Workspace hierarchy    | Missing               | Organization → Project directly                                                                                                                                         | Workspace → Space → Folder → List                                                                                                           |
| Programmes             | Missing               | none                                                                                                                                                                    | Programme grouping projects                                                                                                                 |
| Projects               | Partial               | Code, manager, dates, statuses (planning → active → on hold → completed / cancelled), team with allocation %, milestones, risks, progress, health, activity feed        | Proposals, objectives, templates, documents, project-to-project dependencies, closure workflow, configurable lifecycle (six stages)         |
| Tasks                  | Partial / Refactor    | Numbered tasks, one assignee, four fixed statuses, priority, start and due dates, estimate, dependencies with cycle check, comments, history (activity), board ordering | Subtasks, checklists, multiple assignees, custom statuses, recurring, mentions, attachments, templates, bulk actions, custom fields, search |
| Views                  | Partial               | Board, task list, timeline, team overbooking, My work with a weekly timesheet                                                                                           | Calendar, Gantt, table, workload, activity, mind map, whiteboard                                                                            |
| Time                   | Done / Partial        | Own time and anyone's (permission), costed at the person's rate when logged                                                                                             | Time approvals                                                                                                                              |
| Budgets                | Done / Partial        | Versions (draft → submitted → approved → superseded), approval by someone else, revisions, history, by category                                                         | Periods and multi-year, templates, allocation by activity or department, transfers as a named record                                        |
| Spending               | Done / Partial        | Request → approve (commit) → pay, with separation of duties, availability checks (category and grant), documents, cancel and void, direct record path                   | Configurable approval thresholds and multi-level approval, refunds, adjustments, per-transaction currency, utilization alerts               |
| Multi-currency         | Partial               | Integer minor units, bcmath conversion, explicit rate on grant allocations                                                                                              | Rate dates, per-expense currency, reporting currency, gain/loss                                                                             |
| Grants                 | Done (receiving side) | Funders, pipeline, award, numbered amendments, allocations across projects and currencies, disbursement schedule, donor reports, closeout guards, restricted flag       | Subgrants, partner grants, microgrants, restriction rules, donor portal                                                                     |
| Procurement            | Missing               | none                                                                                                                                                                    | Nine-stage lifecycle                                                                                                                        |
| MEAL                   | Missing               | (risk register only)                                                                                                                                                    | Results framework, indicators, reporting, evaluations                                                                                       |
| Data collection        | Missing               | none                                                                                                                                                                    | KoboToolbox, ODK Central, native forms, offline                                                                                             |
| Beneficiaries          | Missing               | none                                                                                                                                                                    | Registration, households, services, feedback, safeguarding                                                                                  |
| Resources / HR         | Partial               | People directory with hourly cost, allocations, timesheets                                                                                                              | Assets, vehicles, travel requests and advances, time approvals                                                                              |
| Risks and issues       | Partial               | Risk register: probability × impact, owner, mitigation, review date, status                                                                                             | Categories, contingency, issue tracking and escalation                                                                                      |
| Communication          | Partial               | Task comments, project activity feed                                                                                                                                    | Mentions, notification centre, announcements, meetings, minutes, decisions                                                                  |
| Documents              | Partial               | Expense documents (private, authorized download)                                                                                                                        | General document store, versions, permissions, search                                                                                       |
| Notifications          | Partial               | Invitation and account emails                                                                                                                                           | In-app notifications, reminders, digests                                                                                                    |
| Dashboards and reports | Partial               | Portfolio home (health, open budgets against spend), grant overview, approvals queue                                                                                    | Role dashboards, exports (PDF, Excel, CSV)                                                                                                  |
| AI assistant           | Missing               | none                                                                                                                                                                    | Permission-aware assistant                                                                                                                  |
| App Center             | Missing               | none                                                                                                                                                                    | Connector framework                                                                                                                         |
| Public website         | Done                  | Home and features on the main domain                                                                                                                                    | Pricing, contact and legal pages when the content exists                                                                                    |

---

## 3. SSO dependency analysis

The former SSO (OIDC + PKCE through the central platform, JWT/JWKS checks, a user mirror, a
per-request access call, webhooks; S1–S11 in `docs/phase-1-audit.md` §2) **has been removed and
replaced**:

| Former dependency                                       | Replacement now in place                                            | Verified by                               |
| ------------------------------------------------------- | ------------------------------------------------------------------- | ----------------------------------------- |
| OIDC sign-in, callback, logout                          | Fortify login and logout                                            | `AuthenticationTest`                      |
| JWT/JWKS verification                                   | Not needed (session authentication)                                 | Package removed                           |
| User mirror with no password                            | `users` with Argon2id password, verification and status; UUIDs kept | `AuthenticationTest`, `InvitationsTest`   |
| Per-request access API (503 when the platform was down) | Local memberships and role assignments                              | `AdministrationTest`, `OrganizationsTest` |
| Platform `Gate::before`                                 | App `Gate::before` with the same permission keys                    | Every feature test                        |
| Webhooks for revocation                                 | Not needed: access changes are immediate                            | `AdministrationTest` (suspend, remove)    |
| Mobile bearer API                                       | Dropped (it was unused)                                             | —                                         |

`IndependenceTest` asserts that:

- no `platform.*` route exists;
- no `miles/*` Composer package or path repository is installed;
- sign-up → organization → project works with no outbound HTTP call.

**Outstanding for the owner:** revoke the old application's credentials on the central platform.
That is outside this repository.

## 4. Central database dependency analysis

There never was a shared database connection or cross-database query (`docs/phase-1-audit.md`
§3). Coupling went through the platform API and shared packages (C1–C9). All of it is replaced:

- **Tenant primitives:** now in `app/Tenancy`, failing closed.
- **Organizations, memberships and roles:** now local tables.
- **The outbox:** removed.
- **Security headers:** now an app middleware.
- **UI kit:** vendored into `resources/js/ui`.
- **Path repositories:** removed.

The application runs on its own PostgreSQL database alone. No data was migrated from the
platform, by decision: there was no production data. The pre-cutover snapshot remains at the
scratchpad path recorded in `docs/phase-2-report.md`.

---

## 5. Proposed standalone architecture (target)

Keep the modular monolith. Each domain gets an `app/Actions/<Domain>` rules class (or a folder of
them), a controller folder, a `routes/web/<domain>.php` file and `pages/portal/<domain>`. This is
the pattern already used by Grants, Budgets and Spending.

### 5.1 Work hierarchy (new; resolves "folder, list and project are not the same")

```
Organization (tenant, its own subdomain)
 └─ Workspace       optional grouping: a country office, a department
     └─ Space       an area of work with its own members and defaults
         └─ Folder  groups lists
             └─ List    holds tasks; carries its own status workflow
                 └─ Task → Subtask (one level by default)

Programme ── groups Projects (a funding or strategic portfolio)
Project ──── the accountable business entity: budget, funding, results, team, risks
             linked to the Space (or Folder) where its work is organized
```

Responsibilities:

- **Project** owns money and accountability: budget versions, grants, spending, procurement,
  indicators, the team.
- **Lists** own the organization of work.
- A task always belongs to a list. It may reference a project (and later a results-framework
  activity), so financial and MEAL reporting can roll up by project. A Space can hold several
  projects, or work that belongs to no project.
- **Migration of today's tasks:** each existing project gets a default Space, Folder and List,
  and its tasks are attached to that list. `tasks.project_id` stays, for authorization and
  metrics.
- All views (list, board, calendar, Gantt, table, workload) read the same `tasks` rows. No view
  stores its own copy.

### 5.2 Cross-organization collaboration and donor access

Today every record belongs to exactly one organization, and the funder is a directory entry. To
let a donor organization or an implementing partner on the platform see a project:

- **`project_participants`** (project, participant organization, role: lead, implementing
  partner, donor, government; status). This models the organization relationships. No
  organization has a single permanent type: the existing `capabilities` stay descriptive.
- **`Funder.organization_ref`** optionally links a funder record to the organization of that
  donor on the platform.
- **Scoped role assignments.** `role_assignments.scope_type` and `scope_id` already exist. Today
  only `organization` scope is resolved. Add `project` scope, so partner staff hold a role on one
  project only.
- **The donor portal reads projections, never the global scope relaxed.**
    - Donor pages run in the donor's own organization.
    - They query shared data through a dedicated read service. It returns only what the owning
      organization marked as shared with that participant: approved budget versions, financial
      summary, approved reports, indicator results, evidence marked shareable.
    - `OrganizationScope` is never bypassed generically. Each shared projection is a reviewed query
      with its own tests (IDOR and cross-tenant).
- Confidential records (internal notes, safeguarding, individual beneficiaries) are never in a
  projection. Donors see aggregated or de-identified results.

### 5.3 Authentication additions

- **TOTP two-factor:** Fortify's two-factor feature, with an encrypted secret and hashed recovery
  codes. Optional per user; an organization policy can require it for finance roles.
- **Session list and revocation:** the sessions are already stored in the database.
- **Self-service account deactivation.**

### 5.4 Security model (unchanged principles)

- Authorization on the server for every action:
    - route permission;
    - the domain action's own rules, such as separation of duties;
    - tenancy scope.
- Sensitive domains get their own permissions and their own tables, never flags on ordinary
  records: safeguarding cases, protection data, beneficiary personal data.
- Integration credentials: encrypted columns (Laravel `encrypted` cast), never sent to the
  browser.
- AI: calls go through a service that receives only data the user may already see, with
  safeguarding and beneficiary personal fields excluded. AI never performs an approval.

---

## 6. Database migration strategy

- **Current schema:** 9 migrations covering:
    - identity, cache and jobs;
    - organizations and access;
    - audit;
    - projects and work;
    - funding;
    - financial controls.
- **Rule from now on:** every change is an **additive, reversible migration with a backfill**, as
  `2026_11_04_000600_create_financial_controls` did (existing lines backfilled into a draft v1,
  then the column made NOT NULL). No destructive change to a table that may hold data.
- **Per change:**
    1. Write the migration with `down()`.
    2. Run up, down and up again on PostgreSQL.
    3. Backfill inside the migration, in a transaction.
    4. Validate counts and sums in a test or an artisan check.
- **Example: the work hierarchy.**
    1. Create `workspaces`, `spaces`, `folders` and `lists`.
    2. Add a nullable `tasks.list_id`.
    3. Create the default structure per project and set `list_id`.
    4. Verify that every task has a list and that per-project task counts are unchanged.
    5. Make the column NOT NULL.
- **Financial and safeguarding tables:**
    - Money is integer minor units. Rates are `decimal(20,8)`.
    - Approved versions and decided spending are never updated in place. Corrections are new rows
      or status changes, with an audit event.
- **Rollback:**
    - `migrate:rollback` for the step.
    - A database dump before each phase: `pg_dump`, kept with the release.
    - Version control for code, as soon as git is set up.

---

## 7. Multi-organization authorization strategy

| Layer                     | Mechanism                                                                                         | Status                                  |
| ------------------------- | ------------------------------------------------------------------------------------------------- | --------------------------------------- |
| Tenant isolation          | Host → organization; global scope on every tenant model; fails closed                             | Done                                    |
| Membership                | Active membership of an active organization required                                              | Done                                    |
| Organization roles        | Catalog permissions per role, synced from `config/access.php`                                     | Done                                    |
| Owner                     | Holds every permission in their organization                                                      | Done                                    |
| Project-scoped roles      | `role_assignments` with `scope_type = project`                                                    | Next (column exists)                    |
| Participant organizations | `project_participants` + shared projections                                                       | Next                                    |
| Confidentiality           | Per-record visibility for documents and reports: internal, shared with participants, public       | Later                                   |
| Sensitive domains         | Separate permissions and tables (safeguarding, beneficiary personal data); access audited on read | With beneficiaries                      |
| Separation of duties      | In domain actions (requester ≠ approver ≠ payer; submitter ≠ budget approver)                     | Done for finance; reuse for procurement |

Testing rule for every new domain: a cross-organization test (another tenant's id under this
tenant → 404) and an IDOR test (a child record under the wrong parent → 404). This is already the
pattern in `FinanceTest`, `GrantsTest` and `ProjectsTest`.

---

## 8. Financial architecture recommendations

### Definitions (to be the single source used by every report)

| Term               | Definition in this system                                                                                                            |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------ |
| Approved budget    | Total of the project's approved budget version, by category                                                                          |
| Allocated          | Grant allocations to the project, in project currency at the recorded rate. Later also allocations by activity, department or period |
| Commitment         | Approved spending not yet paid. Later also issued purchase orders not yet invoiced                                                   |
| Actual expenditure | Paid spending, plus labour (time at cost)                                                                                            |
| Payment            | A recorded payment of approved spending. Today one payment per expense, so payments equal non-labour actuals                         |
| Available          | Approved budget − commitments − actual expenditure                                                                                   |

### Recommendations, in order

1. **Configurable policies instead of constants.**
    - An `approval_policies` table per organization: amount bands, the permission or role required,
      and the number of approval levels. It can be overridden per grant for donor rules.
    - Utilization alert thresholds per organization and per grant.
    - Replace the fixed 20-point health rules with these settings.
2. **Transaction kinds.**
    - Add refund and adjustment as distinct, linked records (not edits). Each references the
      original and carries a reason.
    - Voiding stays as it is.
3. **Per-transaction currency.** For an expense in another currency, store:
    - the original currency and amount;
    - the rate and the rate date;
    - the converted project amount.

    Reuse `Money::convert` (bcmath, half up). Record gain or loss only when the payment rate
    differs from the commitment rate. The organization can turn this off.

4. **Budget periods.** Version lines gain an optional period (year or quarter), for multi-year
   budgets and period availability checks.
5. **Procurement links into the same commitment model.**
    - An approved purchase order creates the commitment.
    - The goods receipt and invoice turn it into expenditure.
    - The availability check is the same `Spending` service, so there is never double counting.
6. **Finance dashboard and exports**, computed only by backend services (`ProjectMetrics`,
   `Spending`, `Budgets`). The frontend never sums money.

---

## 9. Implementation roadmap

The brief's eleven phases, against where the code stands. Phases 1, 2 and most of 4 and 5 are
done (the earlier specification ordered finance and grants before advanced work management).

| Brief phase                | State                                    | Remaining scope                                                                                                                                                            | Depends on                                    |
| -------------------------- | ---------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------- |
| 1 Audit                    | Done (`phase-1-audit.md`, this document) | —                                                                                                                                                                          | —                                             |
| 2 Standalone foundation    | Done, except MFA and session management  | TOTP 2FA, session list and revoke, self-deactivation                                                                                                                       | —                                             |
| 3 Core project management  | Partial                                  | Work hierarchy, programmes, subtasks, checklists, multiple assignees, custom statuses, attachments, mentions, in-app notifications, project templates, objectives, closure | Phase 2                                       |
| 4 Finance                  | Mostly done                              | Configurable approval policies and thresholds, refunds and adjustments, per-transaction currency, periods, finance dashboard, exports                                      | Phase 3 (activities) for activity allocations |
| 5 Donors and grants        | Receiving side done                      | Participants, project-scoped roles, donor portal (shared projections), subgrants and partner grants                                                                        | §5.2 authorization                            |
| 6 Procurement              | Not started                              | Nine stages, linked to commitments                                                                                                                                         | Phase 4 policies                              |
| 7 MEAL and data collection | Not started                              | Results framework, indicators, reporting periods, evaluations; then KoboToolbox, ODK and native forms                                                                      | Phase 3 (activities)                          |
| 8 Beneficiaries            | Not started                              | Registration, households, services, feedback, safeguarding isolation                                                                                                       | Phase 7 (indicators for reach)                |
| 9 Advanced work management | Not started                              | Calendar, Gantt, table, workload, custom fields, automations, docs and wikis, goals                                                                                        | Phase 3                                       |
| 10 AI and integrations     | Not started                              | Connector framework, App Center, permission-aware AI                                                                                                                       | Phases 3–8 data                               |
| 11 Stabilization           | Continuous                               | Browser tests, performance, accessibility, backup and restore drill, deployment                                                                                            | All                                           |

### Acceptance criteria (apply to every phase)

- The feature works end to end on PostgreSQL. `composer ci:check` and `npm run build` pass.
- Every new route has:
    - an authorization test;
    - a cross-organization 404 test;
    - for financial or sensitive actions, a separation-of-duties test.
- Migrations are reversible (up, down and up again verified) and backfill existing rows.
- Money is integer minor units, with no floats. Every financial change is audited.
- Pages exist for every feature claimed: no placeholders. They are checked in a real browser
  before the phase is reported done.
- A phase report in `docs/` lists what was tested, what was not, and the known gaps.

### Main technical risks

| Risk                                              | Mitigation                                                                             |
| ------------------------------------------------- | -------------------------------------------------------------------------------------- |
| Cross-organization sharing leaks data             | Shared projections as dedicated queries with tests; the global scope is never relaxed  |
| The work-hierarchy refactor breaks existing tasks | Additive migration with backfill and count checks; keep `tasks.project_id`             |
| Scope creep: the brief describes several products | Phase gates; each increment usable on its own                                          |
| Safeguarding data exposure                        | Separate tables and permissions, read auditing, excluded from AI and donor projections |
| External APIs (Kobo, ODK, AI) change or fail      | Connectors are optional, isolated behind interfaces, with recorded test fixtures       |
| No git                                            | Initialize before Phase 3                                                              |

---

## 10. Prioritized task list and first-phase plan

### Do now

1. **Initialize git** and commit the current, verified state. This needs the owner's go-ahead:
   commits are only made on request.
2. **Close the Phase 2 foundation:**
    - TOTP two-factor authentication (Fortify), optional per user, with recovery codes;
    - a list of signed-in sessions with "sign out other sessions";
    - self-service account deactivation (audited).
3. **Configurable thresholds:**
    - Move the project health thresholds into organization settings, with today's values as
      defaults.
    - Add `approval_policies` with amount bands and required levels, enforced in `Spending::approve`.
4. **Work hierarchy, step 1:**
    - Workspaces, spaces, folders and lists.
    - Tasks moved to lists by backfill.
    - Navigation and a list page.

### Do next

- (Done: subtasks, checklists, multiple assignees and attachments, in
  `phase-9-task-depth-report.md`; custom statuses per list, in `phase-10-list-statuses-report.md`.)
- ~~Mentions with in-app notifications and a notification centre.~~ (Done: `phase-11-notifications-report.md`.)
- Programmes, and project objectives, templates and closure checklist.
- Project participants, project-scoped roles and the donor portal (read-only projections).
- Refunds and adjustments, per-transaction currency, budget periods, the finance dashboard and
  exports.

### Do later

- Procurement (nine stages, purchase orders as commitments).
- MEAL: results framework, indicators, reporting, evaluations.
- Beneficiaries with safeguarding isolation.
- Calendar, Gantt, table and workload views; custom fields; recurring tasks.
- Data collection: KoboToolbox first, then ODK Central, then native forms.

### Future / optional

- Automations, docs and wikis, whiteboard, mind map, OKRs and sprints.
- AI assistant, App Center, and connectors (Microsoft 365, Google, Slack, Power BI, QuickBooks,
  n8n).
- Offline data collection.
- Asset, vehicle and travel management.

### First-phase plan (the next increment)

Close the foundation (Do now, items 2 and 3), because these are small, independently
verifiable, and touch nothing else:

| Step | Change                                                                                                                                                         | Verification                                                                                        |
| ---- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| 1    | Enable Fortify two-factor (confirm-on-enable, recovery codes); challenge page; account settings section                                                        | Tests: enable, confirm, challenge, recovery-code use, wrong-code throttle; secrets stored encrypted |
| 2    | Sessions list (device, IP, last activity) with "sign out other sessions" (password confirmation)                                                               | Tests: other sessions deleted, current kept, audited                                                |
| 3    | Deactivate my account (password confirmation; refused for the only owner of an active organization)                                                            | Tests: deactivated user cannot sign in; last-owner guard                                            |
| 4    | Organization settings: health thresholds (defaults = today's 20 points)                                                                                        | `ProjectMetrics` tests with custom thresholds                                                       |
| 5    | `approval_policies`: amount bands → required permission and number of approvers; `Spending::approve` enforces them; the second approver differs from the first | Finance tests for each band, and for the same person approving twice                                |

Then the work-hierarchy step (Do now, item 4) as its own increment with its own report.
