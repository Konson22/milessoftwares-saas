# Phase 6, increment 1: grants and donors

Date: 2026-11-04. Follows `docs/phase-5-report.md`.

## Summary

The first NGO workflow works end to end, from the receiving organization's side:

1. A funder is recorded.
2. A grant moves from opportunity → applied → awarded.
3. It is allocated to several projects, across currencies at an explicit rate.
4. Its payments are scheduled and received.
5. Its amount and end date change only by numbered amendment.
6. Reports owed to the funder are tracked.
7. It is closed out, which is refused while any report is owed.

Each project's budget page shows the grants funding it and the gap.

## Spec coverage (sections 9 and 11)

| Requirement                                                                 | Status                                                                                                                                                                                                                 |
| --------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Donor organizations                                                         | Funders: a per-organization directory with kind, contact and country. Not a global organization type (section 6).                                                                                                      |
| Grant opportunities, applications, agreements                               | One `grants` record through the stages; awarded = the agreement.                                                                                                                                                       |
| Funding allocations; multiple donors per project and projects per agreement | `grant_allocations` (grant ↔ project); projects are never duplicated.                                                                                                                                                  |
| Disbursement schedules, donor disbursements                                 | `grant_disbursements`: scheduled, then received.                                                                                                                                                                       |
| Grant reporting requirements, donor reporting                               | `grant_reports`: due → submitted → accepted / revision requested.                                                                                                                                                      |
| Grant amendments                                                            | `grant_amendments`: numbered, immutable, before and after values.                                                                                                                                                      |
| Grant closeout                                                              | Refused while a report is owed; the totals are audited at close.                                                                                                                                                       |
| Restricted funding                                                          | A flag per grant.                                                                                                                                                                                                      |
| Grant balances, funding utilization                                         | Allocated / left to allocate / scheduled / received per grant; funded vs budget per project.                                                                                                                           |
| Multi-currency with explicit exchange rates                                 | Allocation rate stored; exact bcmath conversion, rounded half up.                                                                                                                                                      |
| Budget availability checks; no double counting                              | Allocations and payments are capped by the grant, checked on the locked grant row in a transaction.                                                                                                                    |
| Do not overwrite approved financial records; traceable corrections          | The awarded amount changes only by amendment; received payments and submitted reports cannot be deleted; everything is audited.                                                                                        |
| **Not yet**                                                                 | Subgrants and partner funding, and the donor portal: they need cross-organization grants (project/agreement-scoped roles). Spending attributed to a specific grant (expense ↔ grant) comes with the finance increment. |

## Changes

- **Migration** `2026_11_04_000500_create_funding_tables`: funders, grants, grant_allocations,
  grant_disbursements, grant_reports, grant_amendments. All are tenant-scoped with
  organization foreign keys.
- **Models**: Funder, Grant, GrantAllocation (`exchange_rate` cast `decimal:8`, an exact
  string), GrantDisbursement, GrantReport, GrantAmendment (immutable).
- **Enums**: FunderKind, GrantStatus, GrantReportStatus (its allowed transitions).
- **`App\Actions\Grants`**: all the money rules, in transactions with the grant row locked.
- **`Money::convert()` and `Money::isRate()`**: decimal-exact currency conversion. This adds
  the `ext-bcmath` requirement to `composer.json`.
- **Controllers** (`Http/Controllers/Funding/`): FunderController, GrantController,
  GrantFundingController.
- **Routes** (`routes/web/funding.php`): 25 routes. Nested records resolve through their grant
  (scoped bindings).
- **Permissions**: `grants.view` and `grants.manage`.
    - Organization Administrator: both.
    - Finance Controller: both.
    - Project Manager: view only.
- **Pages**: `funding/funders/{index,form}`, `funding/grants/{index,form,show}`; the budget page
  gains a Funding panel; the sidebar gains a Funding group.

## Tests run

| Check                                                          | Result                                                                                                                                                                                                                                                                                                                   |
| -------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `composer ci:check` (format, lint, types, Pint, PHPStan, Pest) | pass: 58 tests, 750 assertions                                                                                                                                                                                                                                                                                           |
| `npm run build`                                                | builds                                                                                                                                                                                                                                                                                                                   |
| New `GrantsTest` (9 tests)                                     | funders; lifecycle and amendment immutability; allocation caps and the amendment floor; exact conversion (SSP 2 decimals, JPY 0 decimals); payment schedule; closeout blocked by owed reports; pages and budget funding; view-only vs manage; tenant isolation (another org's grant 404, another org's project rejected) |
| Postgres run                                                   | migrated and synced (11 permissions); the full workflow ran over HTTP; USD 1,000.01 @ 2450.5 = SSP 245,052,451 minor stored exactly; amendment and audit trail confirmed; then reset                                                                                                                                     |

## Remaining issues

1. Subgrants, partner funding and the donor portal need project/agreement-scoped role
   assignments (the `scope_type` column exists; enforcement does not yet).
2. Expenses are not yet attributed to a grant, so "spent per grant" isn't reported. This is
   planned with the finance increment: expense requests, approvals, commitments.
3. Totals across currencies are shown per currency, never converted.
4. Carried over:
    - Revoke the old platform credentials.
    - Add the Mailtrap credentials.
    - Two-factor sign-in.
    - A platform-admin UI.
    - Registration reveals that an email is taken.
    - The PHPUnit dev advisory.

## Next increment

**Financial management (section 9):**

- Expense requests → approvals → commitments → payments, against budget lines and the grant
  funding them, with availability checks.
- Budget submission and approval, with version history.

That makes "spent per grant" and budget-versus-actual per funding source possible, and it is the
base the procurement module builds on.
