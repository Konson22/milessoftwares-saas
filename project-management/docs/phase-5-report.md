# Phase 5 report: restructure application modules

Date: 2026-11-04. Follows `docs/phase-3-4-report.md`.

## Summary

The code is grouped by business domain, the duplicate `workspaces` table is merged into
`organizations`, and the migrations are rebuilt as one clean standalone schema with no
platform-era steps. Every URL, route name, permission check and screen is unchanged. The old
data was deleted with your approval.

## Changes

### Schema rebuilt (local database reset)

Seven migrations now describe the whole schema:

| Migration                                      | Creates                                                                                               |
| ---------------------------------------------- | ----------------------------------------------------------------------------------------------------- |
| `0001_01_01_000000_create_users_table`         | users (with Fortify columns from the start), password_reset_tokens, sessions                          |
| `0001_01_01_000001/2`                          | cache, jobs (Laravel, unchanged)                                                                      |
| `2026_11_04_000100_create_organization_tables` | organizations (now with `currency`, `timezone`), memberships, invitations                             |
| `2026_11_04_000200_create_access_tables`       | permissions, roles, role_permissions, role_assignments                                                |
| `2026_11_04_000300_create_audit_events_table`  | audit_events                                                                                          |
| `2026_11_04_000400_create_project_tables`      | the 12 project tables, each with an `organization_id` foreign key (restrict on delete) from the start |

Gone:

- `create_workspaces_table` and `drop_slug_from_workspaces_table`.
- `add_local_authentication_to_users_table`: folded into users.
- `link_tenant_tables_to_organizations`: folded into the project tables.
- `drop_platform_client_tables`, from Phase 4. Those tables can't exist on a fresh database, so
  its guard and test went too.

### `workspaces` merged into `organizations`

- The `Workspace` model is removed; currency and time zone are organization columns.
- `Money::currency()` reads the current organization.
- Setting up an organization and editing its settings write one row instead of two.
- The duplicate `workspace` shared prop is gone; pages use `organization`.

### Code grouped by domain

| Domain                     | Routes                         | Controllers                                   |
| -------------------------- | ------------------------------ | --------------------------------------------- |
| Organizations and accounts | `routes/web/organizations.php` | `Organizations/`, `Account/`                  |
| Projects                   | `routes/web/projects.php`      | `Projects/` (portfolio, project, team, risks) |
| Work                       | `routes/web/work.php`          | `Work/` (tasks, my work, time)                |
| Finance                    | `routes/web/finance.php`       | `Finance/` (budget, expenses)                 |
| People                     | `routes/web/people.php`        | `People/`                                     |
| Administration             | `routes/web/admin.php`         | `Admin/`                                      |

- `routes/web.php` keeps only the `/o/{organization}` wrapper and its middleware.
- A dump of all 82 routes (method, URL, name, controller method, middleware) was diffed before
  and after the split: identical.
- Models stay in `app/Models`, the Laravel convention, which avoids churning relationships and
  factories.
- The README has a code-layout table.

### Duplication removed

- Organization details were validated and normalized twice: when setting one up and in Settings.
  Both now use `Concerns\ValidatesOrganizationDetails`.
- Setting up an organization now also accepts the optional legal name.

### Naming

- Remaining "workspace" wording now says "organization": a page description, comments, the
  README, the test helper (`organizationUrl`) and a test title.

## Tests run

| Check                                                          | Result                                                    |
| -------------------------------------------------------------- | --------------------------------------------------------- |
| `composer ci:check` (format, lint, types, Pint, PHPStan, Pest) | pass: 49 tests, 583 assertions                            |
| `npm run build`                                                | builds                                                    |
| Route list before and after the split                          | identical (82 routes)                                     |
| `php artisan route:cache`                                      | builds                                                    |
| `php artisan migrate:fresh --seed` on Postgres                 | 7 migrations, 29 tables, 9 permissions and 5 roles seeded |

There is one test fewer than Phase 4 because the drop-migration test went with its migration.

Live smoke test against Postgres:

1. Sign in.
2. Set up "Hope Juba" with currency SSP.
3. Create project WASH, which inherited SSP.
4. Open the portfolio, the project, its board and budget, Settings and People: all returned 200.

The smoke data was then cleared.

## Remaining issues

Carried over:

1. Revoke the old platform credentials on the central platform.
2. Add the Mailtrap credentials.
3. Two-factor sign-in.
4. Project-scoped roles.
5. A platform-admin UI.
6. Registration reveals that an email is taken.
7. The PHPUnit advisory (dev only).
8. Tests run on SQLite; Postgres was checked by migration and the smoke test only.

## Next phase

**Phase 6, NGO-specific capabilities**, built one working workflow at a time:

1. Grant and donor management: donors, funding agreements, budgets against grants, reporting
   deadlines.
2. Financial management beyond project budgets.
3. Procurement.
4. MEAL: indicators, targets and results.
5. Beneficiary management, with privacy controls.
6. A donor portal: read-only, limited by funding agreement. This needs the project- and
   agreement-scoped role grants.
7. Data collection.
