# Project money: metric definitions and data ownership

Status: **in force** (stabilization milestone 2026-11-16; implemented in the
"One set of numbers" milestone, 2026-11-16). One service computes every
figure: `App\Support\ProjectFinance` (`forProjects`, `for`, `shareable`,
`totalsByCurrency`, `provenanceOf`). Where a page still uses another meaning
it is listed under "Known deviations" below.

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
| Procurement commitment | Purchase orders approved but not yet paid. | `purchase_orders` → their `expenses` in status **approved** (a request not yet approved is not a commitment; this row said requested/approved until 2026-11-16, the code never counted requests) | `App\Actions\Procurement`, `Spending` | **Deviation:** a PO is created as an expense request, so the commitment and the payable are the same row (see ADR-0017 §5.3). |
| Recognized expenditure | Expenditure recognised in the books. | **Finance** ledger (journals posted from `finance.project_expense.paid`) | finance-app `App\Finance\PostEvent` | Projects does not hold accruals; until ADR-0017 is decided Projects reports paid amounts, not recognised expenditure. |
| Paid amount | Money that left for the project. | `expenses` with `status = paid` | `Expense::paid()`, `ProjectFinance::forProjects` (`paidMinor`), `ProjectMetrics::expensesMinor` | What M&E, partner reports and participant views call spending. |
| Personnel cost (estimated) | Time logged on the project × the person's hourly rate typed in People. | `time_entries.cost_minor` (from `people.hourly_cost_minor`) | `ProjectMetrics::labourMinor` | An **estimate** for internal management. Not expenditure, not payroll. Never added to paid amount outside the project's own pages. Payroll is the source of real staff cost (not yet integrated). |
| Available budget | What is left to commit against the approved budget. | approved budget − committed − paid − personnel estimate; per category, the labour line is used by the personnel estimate (staff cost cannot be booked as an expense, `ExpenseController` refuses category labour), every other line by approved and paid expenses | `ProjectFinance` (`availableMinor`), `BudgetController` (by category), `Spending::categoryAvailable` (approval check, non-labour lines) | Only defined once a budget is approved. Internal only: it includes the estimate. |
| Available cash | Cash in hand for the project. | **Finance** (bank and cash accounts) | finance-app | Projects cannot compute it: it does not hold bank balances. Petty cash boxes are a partial view only. |
| Original budget | The first approved budget version. | `budget_versions` with the lowest `number` among approved/superseded | `Budgets` (history) | Kept for variance against the plan as signed. |
| Outstanding commitment | Committed but not yet paid. | expenses approved (or PO-linked) and not paid, minus partial payments when ADR-0017 linked mode exists | `Spending` | Never added to paid amount when showing "used"; shown beside it. |
| Staff cost allocation | Real personnel cost charged to the project. | **Payroll** (not yet integrated) | — | Distinct from personnel cost (estimated). When it exists, the estimate is not shown next to it as spending: one or the other, never both. |
| Budget variance | Approved budget − (paid + outstanding commitment), per category and total. | derived | — | The partner-report snapshot's `varianceMinor` is a different, cash-basis figure: approved − paid ("not yet paid"), labelled so, with `basis: paid`. |
| Budget to plan against (internal) | The approved budget; else the draft being prepared; else the typed estimate. | `ProjectFinance` `budgetMinor` + `budgetBasis` (approved / draft / estimate / none) | `ProjectFinance` | Owner pages only, labelled by basis. Only `approved` leaves the organization. |
| Cost to date (internal) | Paid + personnel estimate. | `ProjectMetrics::actualMinor` | `ProjectMetrics` | Owner finance viewers only, labelled "Cost to date … includes estimated staff time". Health uses it against the budget to plan against. | Uses the approved, not the working, version. Recognized expenditure replaces paid once Finance is linked (labelled). |

## Required attributes of every figure (provenance)

Every money figure leaving the project's own finance pages carries:

| Attribute | Values |
|---|---|
| currency | ISO code. Figures in different currencies are never summed; a converted figure states rate and date. |
| period | as-at date or from–to (`provenance.asOf`) |
| source | `projects`, `finance`, `payroll`, or `reported` (typed from external accounts) |
| status | `recorded` (Projects record, unverified by Finance), `posted` (Finance journal), `reported` (manual, unverified), `estimate` |

Implemented as `provenance` on shared figures: `{currency, asOf, source: projects, paidStatus, unpostedPayments}`. `paidStatus` is `posted` only when every paid expense's `expense:{id}:paid` event was answered 201/200 by Finance (outbox `delivered`/`duplicate`, the owning organization's own rows); otherwise `recorded`. For a funder it is computed over that grant's payments only.
| basis | `paid` (cash), `recognized` (accrual, Finance only), `committed`, `planned` |

Organizations without Finance only ever produce `recorded`, `reported` or
`estimate` figures; the label "manually reported / unverified" is shown for
the first two. Labour is counted once: either the estimate or the Payroll
allocation, never both, and never inside paid amount.

## Who may see what

| Viewer | Estimated / approved budget, paid | Personnel cost | Reported financial section of a partner report |
|---|---|---|---|
| Owner member with `projects.finance.view` | yes | yes (project pages only) | yes |
| Owner member without it | no | no | only if they review or prepare that report |
| M&E roles (officer, partner, donor viewer, evaluator, enumerator) | no, unless they also hold `projects.view` **and** `projects.finance.view` | never in M&E | n/a |
| Participant with `budget.view` | approved budget by category and paid amount | never | yes |
| Participant without it | no | never | only as the report's submitter or reviewer |
| Funder contact (donor portal) | per grant portal switches | never | n/a |

## Inconsistencies found and corrected (2026-11-16)

| Where | Was | Now |
|---|---|---|
| Portfolio home | summed `budget_minor` and cost across all projects, in every currency, shown in the organization currency; "Spent of open budgets" included estimated staff time | approved budgets and paid in the organization currency only; other currencies listed apart, not summed; projects without an approved budget counted |
| Portfolio "Paid each month" chart | every currency summed | organization currency only |
| Portfolio "Budget used" | typed/draft budgets ranked; amounts formatted in the organization currency | approved budgets only, each in its project currency; used = committed + paid + estimate (as the budget page) |
| Projects list, overview, programme | "Spent" = paid + estimate against a budget of unstated basis | "Cost to date" (labelled as including estimated staff time) against a budget marked approved / draft / estimate; programme totals add the approved part and the count not approved |
| Participant view | approved from lines, paid only | + committed, currency, provenance |
| Partner report snapshot | "Remaining" = approved − paid, unlabelled | labelled "Not yet paid", `basis: paid`, + committed and provenance (old snapshots unchanged) |
| Donor portal spending | no provenance | provenance per grant |
| M&E linked figures | no provenance | `paidStatus` |

## Known deviations (to be corrected, not copied)

- `ProjectMetrics::actualMinor` = personnel cost + paid ("Cost to date"). It
  is shown on the project's own pages to finance viewers, labelled. It must
  not be used in donor or M&E reporting.
- Partner report snapshots (`project_report_versions.content.system.financial`)
  store the paid amount under the key `spentMinor`. Old snapshots are frozen
  and keep the key; the page labels it "Paid to date".
- `projects.budget_minor` holds the working version's total while a project
  has a draft but no approved version (`Budgets::resetProjectBudget`).
  Readers wanting the approved figure must use the version.
- Grant/donor portal figures (`App\Support\DonorProjection`) are per grant
  (expenses charged to that grant): by design, a funder sees its own funding
  agreement, not the project's other money. The same definitions (approved
  = paid status, committed = approved status) apply.
- `paidStatus` reads outbox outcomes. Finance → Projects confirmations
  (ADR-0017 §6.3) do not exist yet; until they do, `posted` means "Finance
  accepted the posting event", not "Finance paid".
