# Project money: metric definitions and data ownership

Status: **in force for new code** (stabilization milestone, 2026-11-16). Where
existing pages still use another meaning it is listed under "Known
deviations" below; they are to be brought in line, not copied.

Every figure about a project's money that is shown outside the project's own
finance pages (M&E, partner reports, participant views, donor portal) must
use one of these definitions, say which one in its label, and never add two
incompatible ones together. Access is checked on the server before a figure
leaves it: `projects.finance.view` for members of the owning organization,
the `budget.view` capability for a participating organization, the grant's
portal switches for a funder contact.

## Definitions

| Metric | Meaning | Authoritative source (today) | Computed by | Notes |
|---|---|---|---|---|
| Estimated budget | A planning figure typed on the project before any budget version exists. | `projects.budget_minor` while the project has **no** `budget_versions` row | `ProjectController::save` | Never shown as "approved". Labelled "Estimated budget" on the project form. |
| Approved budget | The total of the project's approved budget version. | `budget_versions.total_minor` where `status = approved` (one per project) | `App\Support\ProjectFinance::forProjects`, `App\Actions\Budgets::approved` | Null while none is approved. `projects.budget_minor` mirrors it once one is, but readers outside the budget pages use the version. |
| Revised budget | A newer budget version under preparation or awaiting approval. | `budget_versions` with `status` draft/submitted | `Budgets::working` | Not a reportable figure until approved; then it becomes the approved budget and the previous version is superseded. |
| Funding committed | Grant money allocated to the project. | `grant_allocations.amount_minor` (grant currency) | `App\Actions\Grants` | A commitment of the funder, not cash. |
| Funds received | Grant money actually received. | `grant_disbursements` (received) | `App\Actions\Grants`; also sent to Finance as `finance.grant.received` | Cash in. |
| Procurement commitment | Purchase orders raised but not yet paid. | `purchase_orders` → their `expenses` in status requested/approved | `App\Actions\Procurement`, `Spending` | **Deviation:** a PO is created as an expense request, so the commitment and the payable are the same row (see ADR-0016). |
| Recognized expenditure | Expenditure recognised in the books. | **Finance** ledger (journals posted from `finance.project_expense.paid`) | finance-app `App\Finance\PostEvent` | Projects does not hold accruals; until ADR-0016 is decided Projects reports paid amounts, not recognised expenditure. |
| Paid amount | Money that left for the project. | `expenses` with `status = paid` | `Expense::paid()`, `ProjectFinance::forProjects` (`paidMinor`), `ProjectMetrics::expensesMinor` | What M&E, partner reports and participant views call spending. |
| Personnel cost (estimated) | Time logged on the project × the person's hourly rate typed in People. | `time_entries.cost_minor` (from `people.hourly_cost_minor`) | `ProjectMetrics::labourMinor` | An **estimate** for internal management. Not expenditure, not payroll. Never added to paid amount outside the project's own pages. Payroll is the source of real staff cost (not yet integrated). |
| Available budget | What is left to commit against the approved budget. | approved budget − committed (approved, unpaid expenses) − paid | `Spending::approve` (by category) | Only defined once a budget is approved. |
| Available cash | Cash in hand for the project. | **Finance** (bank and cash accounts) | finance-app | Projects cannot compute it: it does not hold bank balances. Petty cash boxes are a partial view only. |

## Who may see what

| Viewer | Estimated / approved budget, paid | Personnel cost | Reported financial section of a partner report |
|---|---|---|---|
| Owner member with `projects.finance.view` | yes | yes (project pages only) | yes |
| Owner member without it | no | no | only if they review or prepare that report |
| M&E roles (officer, partner, donor viewer, evaluator, enumerator) | no, unless they also hold `projects.view` **and** `projects.finance.view` | never in M&E | n/a |
| Participant with `budget.view` | approved budget by category and paid amount | never | yes |
| Participant without it | no | never | only as the report's submitter or reviewer |
| Funder contact (donor portal) | per grant portal switches | never | n/a |

## Known deviations (to be corrected, not copied)

- `ProjectMetrics::actualMinor` = personnel cost + paid. It is shown on the
  project's own overview as "actual" to finance viewers. It must not be used
  in donor or M&E reporting (M&E used it until this milestone).
- Partner report snapshots (`project_report_versions.content.system.financial`)
  store the paid amount under the key `spentMinor`. Old snapshots are frozen
  and keep the key; the page labels it "Paid to date".
- `projects.budget_minor` holds the working version's total while a project
  has a draft but no approved version (`Budgets::resetProjectBudget`).
  Readers wanting the approved figure must use the version.
- Grant/donor portal figures (`App\Support\DonorProjection`) have their own
  definitions per grant; they were not changed in this milestone.
