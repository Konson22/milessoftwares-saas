# Phase 6, increment 2: financial management

Date: 2026-11-04. Follows `docs/phase-6a-grants-report.md`.

## Summary

Budgets and spending now have the controls of spec section 9:

1. A project's budget is drafted in **versions**, submitted, and approved by someone else. Once
   approved, a version never changes. A change is a new version, which supersedes the old one
   when approved.
2. Spending is **requested**, **approved** (committed) and **paid**, by three different people at
   most and two at least. Whoever requested it never approves or pays it.
3. Approval is checked against what is left of the **approved budget** in the category. When a
   grant is charged, it is also checked against what is left of **that grant's allocation** to the
   project.
4. Receipts, invoices and quotes are attached to spending. They are stored privately and
   downloaded only through the app, by those allowed to see that spending.
5. Nothing is deleted. Rejected, cancelled and voided spending stays on record with who did it and
   why.
6. An organization-wide **Approvals** page queues what waits on finance.
7. Grant pages and project Funding panels show what is committed and spent against each grant.

## Spec coverage (section 9)

| Requirement                                    | Status                                                                                                                                                                                                                                                                              |
| ---------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Approved budgets versioned; never overwritten  | `budget_versions`: draft → submitted → approved → superseded. Lines change only in a draft.                                                                                                                                                                                         |
| Budget approvals                               | Submit needs `projects.manage`; approve or return (with a reason) needs `finance.budgets.approve`, by someone other than the submitter.                                                                                                                                             |
| Budget transfers / revisions                   | A revision copies the approved lines into a new draft; the history keeps every version and who approved it. A revision cannot cut a category below what is committed or spent there.                                                                                                |
| Spending requests, approvals, payments         | One `expenses` row: requested → approved → paid, or rejected / cancelled.                                                                                                                                                                                                           |
| Commitments vs expenditure, no double counting | Each row is in exactly one status. Committed = approved, spent = paid. Actual cost (`ProjectMetrics`) counts paid only.                                                                                                                                                             |
| Budget availability checks                     | On approval: approved category budget − committed − paid, in a transaction on the locked project row.                                                                                                                                                                               |
| Segregation of duties                          | Requester ≠ approver ≠ payer of their own request; submitter ≠ approver of a budget. Enforced in `App\Actions`, not just hidden in the UI.                                                                                                                                          |
| Supporting documents                           | `expense_documents` on the private `local` disk under `organizations/{id}/expenses/{id}/`; types and 10 MB limit in `ExpenseDocument::RULES`; served as an attachment with `nosniff`. Removable only while the request awaits a decision.                                           |
| Traceable corrections                          | Voiding a payment needs a reason. The row stays, marked cancelled. Every step is audited (`expense.*`, `budget.*`).                                                                                                                                                                 |
| Grant spending                                 | Spending may be charged to a grant funding the project, only while the grant is active and within its allocation (project currency). Charged spending keeps the allocation from being removed or cut below the charges, and keeps the grant from closing while any of it is unpaid. |
| Exchange rates                                 | Unchanged from increment 1: allocations carry the rate. Spending is in the project's currency.                                                                                                                                                                                      |
| No full general ledger                         | As specified: no accounts, journals or double entry.                                                                                                                                                                                                                                |

## Changes

- **Migration** `2026_11_04_000600_create_financial_controls`:
    - `budget_versions` table.
    - `budget_lines.budget_version_id` column (existing lines backfilled into a draft v1).
    - Status, grant, decision, payment and cancellation columns on `expenses` (existing rows marked
      paid and direct).
    - `expense_documents` table.
- **Rules:**
    - `App\Actions\Budgets`: revise, addLine, removeLine, discard, submit, approve, returnToDraft.
    - `App\Actions\Spending`: request, record, approve, reject, pay, cancel, attach, detach, plus
      the availability queries.
    - `App\Actions\Grants`: allocation and closeout checks against charged spending.
- **Enums**: `BudgetVersionStatus`, `ExpenseStatus`.
- **Models**: `BudgetVersion` and `ExpenseDocument`; `Expense` rewritten; `Project::budgetVersions()`.
- **Controllers** (`Finance/`):
    - `BudgetController`, rewritten.
    - New `ExpenseController` (the Spending tab) and `ApprovalController` (organization-wide).
    - `GrantController::show` adds committed and spent per allocation.
- **Routes**: `routes/web/finance.php` (rewritten), `routes/web/approvals.php` (new).
- **Permissions** (`config/access.php`):
    - New permissions: `projects.expenses.request`, `finance.budgets.approve`,
      `finance.expenses.approve` and `finance.payments.record`.
    - Admin and Finance Controller get all three approval permissions.
    - Team Member and Project Manager can request spending.
    - New **Finance Officer** role: views projects, budgets and grants, and records payments.
- **Pages:**
    - `projects/budget` rewritten: versions, by-category budget / committed / spent / available,
      funding per grant, history.
    - New `projects/expenses` (the Spending tab).
    - New `finance/approvals`.
    - Grant page: committed and spent columns.
    - Navigation: a Finance group. Nav items can now require any of several permissions.
- **Backward compatibility:**
    - `POST projects/{project}/expenses` still records a payment already made in one step, for a
      project manager with finance access. It is marked direct and audited, and is not checked
      against the budget.
    - The old `DELETE expenses/{expense}` route is gone. Removing spending is now a cancel or void
      with a reason (`POST expenses/{expense}/cancel`).

## Tests run

- `composer ci:check` passes (format, lint, types, Pint, PHPStan, Pest).
- `npm run build` passes.
- The suite is 65 tests, 993 assertions, and passes on SQLite in memory **and on PostgreSQL**
  (`DB_CONNECTION=pgsql DB_DATABASE=projects php artisan test`).
- Running on PostgreSQL turned up one test that relied on SQLite returning `sum()` as an integer
  (`GrantsTest`). The app code already casts; the test now does too.
- New `tests/Feature/FinanceTest.php` (7 tests) covers:
    - versioning, submit, return and approve by someone else, supersession, immutable approved
      lines, discarding drafts;
    - the request → approve → pay lifecycle with segregation of duties;
    - category availability, and revisions that would cut below what is committed;
    - committed vs spent, and metrics counting paid spending only;
    - members seeing only their own requests;
    - cancel and void without deleting;
    - grant allocation limits and allocation/closeout guards;
    - document type checks, private download and the stay-once-decided rule;
    - approvals queue visibility;
    - cross-project and cross-organization isolation.
- **Smoke run** against `php artisan serve` on PostgreSQL, as an organization owner:
    1. Created a project.
    2. Added a budget line.
    3. Requested spending.
    4. Recorded a direct payment.
    5. Loaded Budget, Spending and Approvals: all returned 200 with the expected props (draft basis,
       paid spending in actuals, the request queued with "no approved budget").

    The database was then reset (`migrate:fresh --seed`, `access:sync`).

## Remaining issues

- **The new pages were not clicked through in a real browser.** They typecheck and build, and the
  server renders them with the right data, but forms and disclosures were not exercised
  interactively.
- **Segregation needs two people.** A one-person organization cannot approve its own requests or
  budgets. That is intended, but it may surprise a very small NGO. The direct-record path remains
  for a manager who has already paid.
- **The direct-record path bypasses approval and the budget check** by design (backward
  compatibility). It is visible ("Recorded directly") and audited. An organization that wants to
  forbid it can withhold `projects.manage` + `projects.finance.view` from those who shouldn't use
  it. A later setting could switch it off.
- **No approval thresholds or multi-level approval** (for example, above X needs a second
  approver). One approval step only.
- **Requests are not limited to the project's team**: anyone holding `projects.expenses.request`
  can request on any project. Project-scoped roles come with the donor portal and subgrant
  increment.
- **Spending is in the project's currency only**, with no per-expense exchange rate.
- **No partial payments or payment batches**: one payment per approved request.
- **Approvals queue:** up to 200 rows per queue, with no paging. Category availability is computed
  per request (one query each).
- **Documents:** stored on the local private disk with no virus scanning. For production, point
  the `local` disk at private object storage and add scanning.
- **Grant checks use the allocation, not cash received** from the funder.

## Next increment

Procurement: purchase requests → quotations → purchase orders → goods received, linked to the
commitments built here (an approved purchase order commits spending against the budget and grant).
