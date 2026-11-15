# ADR-0016: Project-level access inside an organization

Status: **Accepted as built, pending independent review** (2026-11-16,
stabilization milestone). Uncommitted to production; not deployed.

## Context

Until now `projects.view` was organization-wide: every member holding it saw
every project, and every record of every project (tasks, expenses,
attachments, procurement, partner reports). ADR-0014 §6 deferred
project-scoped access; the audit of 2026-11-15 rated this Medium (D-2) and
listed it as an unresolved Phase 1 requirement.

## Decision

1. **Per-project visibility.** `projects.visibility` is `organization` (the
   default; what every existing project keeps) or `team`.
2. **Who sees a team-only project:** the workspace owner; members holding the
   new permission `projects.all` (catalog 3.3.0: admin, manager, controller,
   finance officer); the project's manager; whoever set it up; the people on
   its team (`project_members`, through the member's `people.user_id`).
3. **Enforced in queries, not pages.** `App\Access\ProjectVisibilityScope` is
   a global scope on `Project`; `App\Access\VisibleThroughProject` (with
   `ProjectBound`) narrows every record that hangs off a project, directly
   (`project_id`) or through a parent (task attachments, comments,
   checklist items, expense documents and approvals, purchase request
   lines, quotes, orders, goods receipts). Route-model binding, lists,
   searches, counts and downloads all go through these scopes, so a
   team-only project's records 404 for others. The owner side of partner
   reports and requests (`App\Collaboration\Acting::forOwner`) re-checks.
4. **It only narrows.** It grants nothing, never applies without a signed-in
   member (console, queue, reminders), and never applies when code runs
   inside another organization on purpose (a participant reading an owner's
   project through `App\Collaboration`, which checks participation itself).
   External participant access is unchanged (ADR-0014).
5. **Revocation is immediate.** Removing someone from the team removes the
   project from them on the next request; nothing is cached across requests.
6. **No self-lockout.** Making a project team-only is refused for someone who
   would no longer see it.
7. **M&E** shows a linked project only to members with `projects.view` who
   may see that project, and money only with `projects.finance.view`
   (`App\Mel\LinkedProjects`).

## Compatibility and migration

- Migration `2026_11_16_000100_add_visibility_to_projects` adds the column
  with default `organization`: on deploy nobody loses or gains access.
- Catalog 3.3.0 adds `projects.all`. Run `php artisan platform:catalog-reassign projects`
  after deploy so existing admin/manager/controller/finance-officer
  assignments receive it. Until it is run, those members are treated like
  any other member on team-only projects — but no project is team-only
  until someone makes it so.
- Rollback: `migrate:rollback --step=2` (this and the report status
  migration) drops the column; every project is organization-wide again.

## Consequences and limits

- Records with no project (`project_id` null: personal task lists, petty cash
  payments not tied to a project) are unaffected.
- Grants themselves stay organization-wide (`grants.*` permissions); their
  allocations to a team-only project are hidden from those outside it.
- Dashboards and portfolio totals for a restricted member now count only the
  projects they see. That is intended.
- Per-project roles (e.g. "viewer on project A, manager on B") are not part
  of this decision: catalog permissions stay organization-wide, visibility
  narrows which projects they apply to.
- Performance: the scope adds one sub-select per query for restricted
  members only; no measurement at volume has been made.
