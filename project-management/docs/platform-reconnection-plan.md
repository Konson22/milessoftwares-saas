# Reconnecting Projects to the central platform (plan, 2026-11-09)

Decided with the owner: **bridge** approach, and **replace the in-app accounting with Finance**.
Backup of the standalone app before any change: `D:\new-projects\project-management-standalone-2026-11-09.tgz`
(baseline: 515 tests passed, 9 skipped).

Status: **M1 done** (2026-11-09), **M2 done** (2026-11-10), both uncommitted: 324 tests pass on
PostgreSQL (319 and 5 skipped on SQLite); PHPStan, Pint, vp check, tsc and the build are clean.
Not yet run against a live central (`platform:local-product`) or a live Finance.

## M1 Platform bridge (done)

1. composer: path repos `../packages/platform-client`, `../packages/shared-module-kit`; `PLATFORM_*` env
   (application `projects`), session cookie `miles_projects_session` path `/projects`, Vite build dir
   `public/projects/build`. Restore ops entries from commit 8a4f138 (apache-dev, nginx, php-fpm).
2. Routes under `PlatformConfig::path()` with `['web','platform', EnterWorkspace, ...]`. Remove
   guest site (central has it), Fortify/Google/account/2FA, organization choose/create, portal and
   donor hosts (`RedirectDonorHost`, `MainDomainOnly`, `RegisterOnWorkspaceHost`).
3. `EnterWorkspace` replaces `ResolveOrganization`: upsert local `organizations` row with id = platform
   org id (slug, name, currency, timezone, status) and the local membership (is_owner); set
   `App\Tenancy\CurrentOrganization` and `App\Access\CurrentAccess` from platform `EffectiveAccess`
   (permissions = union of role permissions / all for owner; roles = roleKeys). This keeps the
   ~141 files using local tenancy untouched. Drop the app's own Gate::before (the package has one).
4. `users` = platform user mirror (uuid = subject); password and 2FA columns nullable/unused.
5. `platform/catalog.json` from `config/access.php` minus `finance.*` and `organization.manage`-type
   admin of members/roles; roles incl. controller, viewer, manager, member, accountant (central
   DemoBusinessSeeder already uses these) and `funder_contact` with one permission
   `projects.donor.view` (central only allows access with a role). `FunderContact::isConfined` = only
   that permission.
6. Provisioning `SetUpWorkspace` creates the local organization and its defaults (what
   `App\Actions\Organizations` create does).
7. Admin: members/roles/invitations/2FA move to central; keep settings (modules, health, funding),
   approval policies, org units, audit.
8. Partnership invitations: email link opens a page on the donor's host (`platform.tenant` only)
   asking for the recipient's workspace, then accepted at
   `{their-slug}.…/projects/partnerships/{token}` for that tenant (needs `organization.manage`).
9. Tests: rewrite `tests/Concerns/InteractsWithOrganizations` on `InteractsWithPlatform` with the
   same method names (`organization`, `member`, `actingAsMember`, `asTenant`, `organizationUrl` →
   workspace URL); delete auth/account/Google/2FA/member-admin tests.
10. Central: add `projects` to `database/erp-products.json` (NGO), `platform:local-product
    project-management --url=http://127.0.0.1:<port>`.

## M2 Accounting → Finance (done)

- Delete `app/Accounting`, `Controllers/Accounting`, `routes/web/accounting.php`, `OpenBooks`,
  accounting pages and tests; drop accounting tables with a new migration.
- Keep expenses, approvals, budgets, petty cash and staff advances as operational records; send
  Finance events through the Outbox instead of `ProjectBooks`/`PettyCash`/`StaffAdvances` journals.
- New Finance events (contracts + `finance-app/app/Finance/PostEvent.php`, project dimension on
  lines): `finance.project_expense.paid` / `.voided` (category account, paid from bank / cash /
  mobile money / staff advance), `finance.grant.received` (Dr bank Cr grant income),
  `finance.staff_advance.issued` / `.settled`; petty cash top-ups via `finance.cash.moved`, counts
  via `finance.cash.counted`. Finance needs accounts: staff advances, grant income, project
  expense categories.

How it came out:

- `App\Support\FinanceEvents` records each event in the platform outbox, in the transaction that
  changes the record (`platform:outbox` delivers it; an organization without Finance has it
  skipped). `App\Actions\ProjectBooks` sends spending and grant payments; `App\Actions\PettyCash`
  and `App\Actions\StaffAdvances` send their own.
- Amounts go in the project's, grant's or advance's currency. Finance converts them at its rate
  for the day (`PostEvent::CONVERTED`), and the event waits (503, retried) until it has one.
- Account choices became a cost category (petty cash payments, advance receipts) and a payment
  method (bank transfer, cash, mobile money) for advances paid out and cash handed back. Petty
  cash moves to and from the bank.
- A petty cash box's balance is worked out here: float, approved top-ups, payments not voided,
  count differences.
- The finance limits (petty cash, advances) moved from the books to `organizations`. The module
  key stays `accounting`, shown as "Petty cash and advances".
- Migration `2026_11_10_000200_hand_the_books_to_finance` drops the ledger tables and the links
  into them (no way back but a backup). Suppliers and document numbers stay
  (`App\Models\Supplier`, `App\Support\DocumentNumbers`).
- Gone with the books: the accounting pages, journal links on expenses and grant payments, the
  invoice PDF, `accounting:recurring`, and the budget-against-actual report (Finance's now).
- Tests read what was sent with `financeEvents()` (`tests/Concerns/InteractsWithOrganizations`).

## Done in M1, beyond the list

- Administration → Members stays, read-only for roles (from the platform) and where members are
  placed in units; it links to the platform's members page.
- Email alerts on/off moved from the account page to Notifications (`account.email-alerts`).
- A funder contact is added by email on the grant; the platform still has to give them a place
  with the _Funder contact_ role. Adding someone already on the team is refused.
- Tests: `InteractsWithOrganizations::call()` puts the workspace in the session for a signed-in
  user (as the platform sign-in does); `member()` forgets the cached access answer (as the
  platform's webhook does). Someone who is not a member gets 403 from the platform, not 404.
- Central: NGO ERP's required application is now `projects` (was `ngo`, which did not exist).
- Not done: a CI job (the folder is its own git repository, not part of the monorepo's history).
