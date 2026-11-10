# Accounting, copied from finance-app

The full Finance product from `milesoftwares/finance-app` now runs inside this app at `/accounting` in each organization. The source folder was only read, never changed.

## What came across

- Chart of accounts, manual, recurring and approved journals, periods and year close, opening balances.
- Suppliers, bills, supplier payments and credits. Invoices and customer payments.
- Bank and cash accounts, statement import, rules and reconciliation.
- Tax codes and returns, cost centres and projects, budgets, other currencies and revaluation, fixed assets and depreciation.
- Reports: profit and loss, by dimension, budget vs actual, balance sheet, cash flow, ratios, trial balance, general ledger, aged receivables and payables.

## Where it lives

| What             | Where                                                                             |
| ---------------- | --------------------------------------------------------------------------------- |
| Rules            | `app/Accounting`                                                                  |
| Models and enums | `app/Accounting/Models`, `app/Accounting/Enums`                                   |
| Controllers      | `app/Http/Controllers/Accounting`                                                 |
| Routes           | `routes/web/accounting.php`, named `accounting.*`                                 |
| Pages and parts  | `resources/js/pages/portal/accounting`, `components/accounting`, `lib/accounting` |
| Tests            | `tests/Feature/Accounting`                                                        |

## What changed in the copy

- **Tables:** `budgets` and `budget_lines` are `accounting_budgets` and `accounting_budget_lines`, because this app already has `budget_lines`. The source's `workspaces` row is `accounting_books` (model `Books`).
- **Opening the books:** an organization's books open the first time anyone visits `/accounting` (`OpenBooks`, `SetUpBooks`): the currency is taken from the organization and fixed from then on, and the starting chart of accounts is created.
- **Plans:** there are no plan features here, so other currencies and fixed assets are always available.
- **Permissions:** the 18 `finance.*` permissions are in `config/access.php`. Administrators hold them all. Accountant, Bookkeeper and Auditor are new system roles. Run `php artisan access:sync` after deploying.
- **Menu:** the main menu has one **Accounting** entry. Inside it, the sidebar shows the accounting sections and **Back to projects**.
- **Schedule:** `accounting:recurring` posts due recurring journals daily at 01:00. It needs the Laravel scheduler running on the server.
- **MariaDB:** one over-long index was renamed and one rollback reordered. The rule of one side per journal line is a database check here too.

## Left out

- **Receiving events over HTTP.** The endpoint, its platform-signed tokens and its inbox table were not copied, because they depend on the Miles platform. `PostEvent` was kept and called directly in tests, ready for future use.
- **Plan-feature tests and platform tests:** `EventsTest`, `PlatformAccessTest`, `ProvisioningTest` and three plan-feature cases.

## Not connected yet

The books and the rest of this app don't share anything yet. Paying a project expense doesn't post a journal, grants don't appear in the books, and an accounting "project" (a dimension) is separate from a project in Projects.

## Differences from the source to know about

On PostgreSQL, the source's database refused changes to journal lines and refused unbalanced journals at commit, through triggers. MariaDB doesn't have those here. The application's ledger still refuses unbalanced journals, but someone with direct database access could change posted lines.

## Tested

- The copied suite on SQLite: bookkeeping, budgets, dimensions, fixed assets, approvals, multicurrency, periods, reconciliation, recurring journals, reports and tax.
- On MariaDB: all 15 migrations up, down and up; 38 accounting pages opened with no parameters; a balanced journal posted and an unbalanced one refused, through the running app.

## Not tested

- The copied suite on MariaDB, which needs a separate test database (the local user can't create one).
- Forms other than the journal, in a browser or on MariaDB: invoices, bills, bank import, tax returns and the rest pass on SQLite only.
- Pages that need a record, such as a single invoice, on MariaDB.
