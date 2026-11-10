# Phases 3 and 4 report: data migration and runtime dependencies

Date: 2026-11-03. Follows `docs/phase-2-report.md`.

## Summary

- **Phase 3 (data migration): nothing to migrate.** As decided at the start, there is no
  production data. The local `projects` database was empty before this work, and the central
  platform's database holds nothing this app needs to keep. No importer was written, no data was
  fabricated, and the central database was not touched.
- **Phase 4 (runtime dependencies): done.** `miles/platform-client` (and
  `miles/shared-module-kit`, which only it needed) are uninstalled. Their env vars, tables,
  catalog and build output are gone. A test now fails if any of them returns. The shared
  `packages` folder they were linked from is untouched.

## Phase 3: data migration

| Spec item                                           | Status                                                                                                                                                                                                        |
| --------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Data mapping                                        | Done in `docs/phase-1-audit.md` (section 6): every app table is app-owned; only identities, organizations and permissions came from the platform.                                                             |
| User identity migration                             | No users to migrate. If any are added later from elsewhere, they get an account without a password and set it through the one-time activation link (Phase 2). Accounts are never matched by unverified email. |
| Organization, project, task and financial migration | Nothing to migrate: the tables were empty.                                                                                                                                                                    |
| Attachment migration                                | The app stores no attachments.                                                                                                                                                                                |
| Reconciliation report                               | Source 0 rows, target 0 rows, for every table.                                                                                                                                                                |

If source data ever turns up, Phase 3 must be redone properly: map platform tenant ids to
`organizations.id`, keep a restricted mapping of old subject ids, reconcile counts, and test on a
copy first.

## Phase 4: runtime dependencies removed

| Spec item                             | Change                                                                                                                      |
| ------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| Replace SSO guards                    | Done in Phase 2 (Fortify session guard). The package and its OIDC routes are now uninstalled.                               |
| Replace central database queries      | There were none. The per-request access API call was replaced in Phase 2.                                                   |
| Update authentication flows           | Phase 2.                                                                                                                    |
| Update API clients                    | The package's HTTP clients (access check, outbox delivery, provisioning) are gone. `grep` finds no outbound HTTP in `app/`. |
| Update organization lookups           | Phase 2 (`ResolveOrganization`, `/o/{slug}`).                                                                               |
| Update permission checks              | Phase 2 (database-backed roles; `Gate::before`).                                                                            |
| Remove obsolete runtime configuration | See below.                                                                                                                  |

Removed:

- **Composer**: `miles/platform-client` and `miles/shared-module-kit` from `require`, both path
  repositories, and the lock entries.
    - The `vendor/miles/*` junctions were unlinked with a non-recursive `rmdir` before running
      Composer.
    - `D:\new-projects\milesoftwares\packages\platform-client` still has all its 11,105 files.
- **`platform/catalog.json`**: superseded by `config/access.php`.
- **`public/projects/build`**: the old build output for the `/projects` path.
- **Env vars** in `.env`, `.env.example` and `phpunit.xml`: `PLATFORM_*`, including the
  sign-in, service-client and webhook secrets in `.env`.
- **Tables**: `platform_outbox`, `platform_provisioned_tenants` and `platform_webhook_events`
  (all empty), plus their three rows in `migrations`.
    - Dropped by `2026_11_03_000600_drop_platform_client_tables`.
    - The migration refuses to run if any of those tables has rows, so undelivered platform
      events can't be dropped silently.
- **Icon links** in `app.blade.php` for `favicon-96x96.png` and `apple-touch-icon.png`: the
  platform's hosts served them, and this app has no copies, so they returned 404.
- **Stale comments** in Controller, PeopleController, Workspace, User and BelongsToOrganization.

Kept on purpose:

- **Old migrations**: they describe the schema history.
- **"Platform administrator"** (`is_platform_admin`): this app's own super-admin flag, not the
  former platform.

## Rollback

- **Snapshot**:
  `C:\Users\konak\AppData\Local\Temp\claude\fc26a364d2a3f897\ff9d185f-9151-4f89-9934-5e07399ce67a\scratchpad\pre-phase4.tar.gz`
  holds the project before Phase 4, without `vendor` and `node_modules`.
- **Restoring**:
    1. Extract the snapshot.
    2. Run `composer install` (the path repositories relink the shared packages).
    3. Run `php artisan migrate`.
- **Env values**: the earlier `.env`, with the platform values, is at `env.pre-phase2.bak` in
  the same folder.
- **Copy the snapshot somewhere permanent.** The scratchpad is temporary. With no git, it is the
  only rollback copy.

## Tests run

| Check                                                          | Result                                      |
| -------------------------------------------------------------- | ------------------------------------------- |
| `composer ci:check` (format, lint, types, Pint, PHPStan, Pest) | pass: 50 tests, 587 assertions              |
| `npm run build`                                                | builds into `public/build`                  |
| `php artisan migrate` on local Postgres                        | `000600` ran; no `platform_*` tables remain |

New tests in `IndependenceTest`:

- Nothing of the platform is installed or configured: no `platform.*` routes, no package class,
  no `platform-client` config, no `miles/*` package or path repository in `composer.json`.
- The drop migration refuses when a platform table has rows, and drops it once empty.

## Remaining issues

1. **Revoke the platform credentials.** The sign-in, service-client and webhook secrets this
   app used are still valid on the central platform. Revoke them there: I did not change the
   central app.
2. **Keep the rollback snapshot** until the app is verified in production.
3. **Carried over from Phase 2:**
    - Mailtrap credentials.
    - Two-factor sign-in.
    - Project-scoped roles.
    - A platform-admin UI.
    - Registration revealing that an email is taken.
    - The PHPUnit advisory.
    - Workspace/organization name duplication.

## Next phase

**Phase 5, restructure application modules:**

- Group the code into business domains (Projects, Work/Tasks, Finance, People, Organizations,
  Access, Audit) without duplicating models or services.
- Merge `workspaces` into `organizations`.
- Keep every UI feature.

Phase 6 then adds the NGO capabilities: grants and donors, procurement, MEAL, beneficiaries, the
donor portal and data collection.
