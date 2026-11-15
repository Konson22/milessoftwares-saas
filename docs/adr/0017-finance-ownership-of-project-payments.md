# ADR-0017: Financial ownership between Projects, Finance and Payroll

Status: **Proposed — for independent review.** Only the read-only
reconciliation report (`projects:finance-sync`, §6.6) is implemented. No
ownership is moved and no financial data is migrated until this is accepted.

Revision 2 (2026-11-16): ownership matrix, integration contracts, failure
states and standalone mode added after the second review brief.

## 1. Target responsibilities (from the brief, confirmed against the code)

- **Projects** owns planning and operational requirements: budgets and
  approved revisions, allocations, procurement requisitions and purchase
  orders (commitments), funding and disbursement *requests*, spending
  *requests*, project financial *views*.
- **Finance** owns execution and the books: payment approval and
  processing, posted expenditure, liabilities, cash and bank, petty cash
  transactions, advances, journals, reconciliation.
- **Payroll** owns payroll calculation and the personnel cost it allocates
  to projects.
- No ledger and no payment-execution engine inside Projects when Finance is
  present. Finance and Payroll are not merged into Projects.

## 2. Every workflow that creates, approves, changes or records money (inspected)

| # | Workflow | Where | Approves | Records money moving | Emits to Finance |
|---|---|---|---|---|---|
| 1 | Budget version prepare → submit → approve / return | Projects `Budgets` | Projects (`projects.manage`, approval) | no | no |
| 2 | Expense request → approve → pay | Projects `Spending::request/approve/pay` (`routes/web/finance.php`) | Projects (`finance.expenses.approve`, budget-left check) | **yes** (`pay`) | `finance.project_expense.paid` |
| 3 | Direct "already paid" expense | Projects `Spending::record` | none (recorded as paid) | **yes** | `finance.project_expense.paid` |
| 4 | Expense cancel / void | Projects `Spending::cancel` | Projects | reversal | `finance.project_expense.voided` |
| 5 | Purchase order raised | Projects `Procurement::raiseOrder` → `Spending::request` | Projects (requester ≠ decider) | no (creates workflow 2's request) | via 2 when paid |
| 6 | Goods received, invoice reference | Projects `Procurement::receive/recordInvoice` | — | no (gates payment) | no |
| 7 | Petty cash payment / void / top-up / count / close | Projects `PettyCash` (when Finance absent) | Projects | **yes** | `finance.cash.moved`, `finance.cash.counted`, `finance.petty_cash.transferred` on handover |
| 8 | Petty cash in Finance | Finance `PettyCash`, `PaymentRequests::replenish` | Finance | **yes** | — |
| 9 | Staff advance request → approve → disburse → retire → settle / write off | Projects `StaffAdvances` | Projects | **yes** (`disburse`, `recordReturn`, write-off) | `finance.staff_advance.issued/settled` |
| 10 | Payment request (supplier, expense, **advance**, transfer) | Finance `PaymentRequests` prepare → review → approve → disburse | Finance (SoD, limits) | **yes** | — |
| 11 | Grant payment received | Projects `Grants` (`grant_disbursements`) | Projects (`grants.manage`) | **yes** (cash in) | `finance.grant.received` |
| 12 | Personnel cost | Projects time × typed hourly rate (`time_entries.cost_minor`) | — | no (estimate) | no |
| 13 | Payroll runs | Payroll (inside finance-app, ADR-0009) | Payroll | **yes** | consumes `payroll.timesheet.submitted` (Projects never emits it) |

**Duplicate ownership found (verified):** rows 2/3 vs 10 (an expense can be
approved and paid in Projects and, independently, paid again through a
Finance payment request of kind `expense` — nothing links them); rows 9 vs 10
(kind `advance`); rows 7 vs 8 (resolved per organization by
`FinanceLink::keepsPettyCash`, the only existing compatibility boundary);
row 12 vs 13 (two sources of staff cost, one an estimate).

**Delivery is not posting (verified):** Finance's `EventController` posts each
event exactly once per transaction id (201 posted, 200 duplicate, 409
conflict, 422 not postable, 503 later). The platform outbox records the
outcome (`delivered`, `duplicate`, `failed`, `skipped`, retries pending), but
until this milestone nothing in Projects read it: a 422 (e.g. currency
mismatch) left an expense "paid" in Projects and absent from the books, with
no one told.

## 3. Ownership matrix

| Business entity | Current authority | Proposed authority | Existing integration | Required change | Migration risk |
|---|---|---|---|---|---|
| Budget and versions | Projects | Projects | none | none | none |
| Budget allocations to grants | Projects | Projects | none | none | none |
| Purchase requisition, PO (commitment) | Projects | Projects | none | Split PO from expense request (§5.3) | **High** (`purchase_orders.expense_id` NOT NULL unique) |
| Goods receipt | Projects | Projects | none | none | low |
| Supplier invoice / liability | Projects (reference only) | Finance (bill) when linked | none | Projects sends `procurement.invoice_recorded`; Finance creates the bill | medium |
| Spending request | Projects | Projects | none | Becomes the request Finance pays (linked mode) | medium |
| Payment approval | **Projects and Finance** | Finance (linked) / Projects (standalone, compatibility) | none | Linked mode routes approved requests to Finance; Projects stops approving payment | **High** (open requests at switch-over) |
| Payment record | Projects (then event) | Finance (linked) / Projects-recorded, unverified (standalone) | `finance.project_expense.paid` | Finance → Projects status events | medium |
| Posted expenditure | Finance (from events) | Finance | events | none | low |
| Petty cash transactions | Projects or Finance per org | Finance (linked) / Projects (standalone) | handover command + events | Keep boundary; no new work | low (exists) |
| Staff advances | **Projects and Finance** | Finance executes (linked); Projects keeps the request and retirement evidence | `staff_advance.*` events | Route disbursement to Finance in linked mode | medium |
| Funds received (grant) | Projects (then event) | Finance confirms receipt (linked); Projects records expectation | `finance.grant.received` | Finance → Projects `funding.receipt_confirmed` | medium |
| Personnel cost | Projects estimate | Payroll allocation (actual); Projects estimate kept, labelled | none | Payroll → Projects allocation; Projects → Payroll timesheets | medium (Payroll owner decision) |
| Reconciliation | none | Finance (books) + Projects report (requests vs outcomes) | none | Read-only report now (§6.6); full reconciliation later | none now |

## 4. Organizations without Finance (standalone mode)

Projects must stay usable. Smallest viable capability:

- **Keep** budget planning, spending requests and approvals, and the existing
  "pay" step as **"recorded as paid in Projects"** — a compatibility
  boundary, not an accounting engine. No journals, no bank accounts, no
  cash balances are added.
- **Label provenance** on every figure built from these records:
  `source: projects`, `status: recorded` (unverified). Only figures from
  Finance carry `status: posted`. Donor and M&E views must show the label
  (docs/finance/metric-definitions.md, "provenance").
- **External statements**: a standalone organization may enter period totals
  from its external accounts as *reported* figures (status `reported`,
  reviewer + date). Not built; proposed as the only addition, and only if
  donors ask for it.
- **Retirement path**: when an organization subscribes to Finance, the
  existing `FinanceLink` probe switches it to linked mode (§5) after a clean
  reconciliation; the compatibility "pay" step is then disabled for it.

## 5. Linked mode: the expected workflow

```
Projects                                   Finance
--------                                   -------
spending request (draft)
  → submitted
  → budget-checked & approved (operational,   
    budget holder; Spending::approve)
  → AWAITING_FINANCE ───── spending.submitted.v1 ──▶ inbox (idempotent)
                                              PaymentRequest (kind expense,
                                              source = projects/expense:{id})
                         ◀── spending.accepted.v1 ── (or .rejected.v1 + reason)
  → ACCEPTED_BY_FINANCE                       review → approve (Finance SoD)
                         ◀── payment.approved.v1 ──
                                              disburse (bank/cash, journal)
                         ◀── payment.completed.v1 ── amount, date, reference,
  → PAID (authoritative)                        journal id
                         ◀── payment.reversed.v1 / payment.failed.v1 ──
  → REVERSED / back to ACCEPTED (failed)
```

State rules:

| Finance outcome | Projects state | Budget effect |
|---|---|---|
| rejected | `returned` (reason shown, may be revised and resubmitted as a new request version) | commitment released |
| cancelled by Projects before Finance acceptance | `cancelled`; sends `spending.cancelled.v1`; Finance withdraws the request if not yet approved, else refuses (409) and Projects stays awaiting | released only on Finance confirmation |
| partially paid | `partly_paid` with paid amount; remainder stays committed until completed or released | paid += amount |
| completed | `paid` | committed → paid |
| failed (bank failure) | back to `accepted_by_finance` | none |
| reversed after payment | `reversed` | paid −= amount; commitment re-opened only if Projects reopens it |

Projects never marks a linked expense paid on its own; "direct" paid
expenses are disabled in linked mode (Finance records them).

### 5.3 Commitments ≠ liabilities ≠ expenditure ≠ payments

| Fact | Event | Counts as |
|---|---|---|
| PO issued | `procurement.commitment.created.v1` (Projects → Finance, informational) | commitment |
| PO closed/cancelled | `procurement.commitment.released.v1` | commitment released |
| Invoice recorded | `procurement.invoice_recorded.v1` → Finance bill | liability |
| Expense posted | Finance journal | recognised expenditure |
| Payment completed | `payment.completed.v1` | paid |
| Advance issued | `advance.issued.v1` (Finance → Projects in linked mode) | receivable from staff, **not** expenditure |
| Advance retired | retirement lines → expenditure; `advance.retired.v1` | expenditure (only the retired lines) |

A PO, its invoice, an advance and the payment are never added together.

## 6. Integration contracts

### 6.1 Conventions (existing, reused)

- Envelope `contracts/events/envelope.schema.json`, `event_type`
  `<module>.<entity>.<fact>`, `schema_version` integer, one JSON schema per
  event in `contracts/events/`.
- Delivery: platform outbox, written in the business transaction; event
  token per organization and audience (`EventTokenVerifier`); retries with
  backoff `60, 300, 1800, 7200, 21600` s, 8 attempts; then `failed` (needs a
  person).
- **Idempotency key** = `source_transaction_id`, stable per fact:
  `projects/expense:{uuid}:submitted`, `finance/payment-request:{uuid}:completed`, …
  The receiver's inbox applies it once (200 on repeat; 409 if the same key
  arrives with different content).
- **Cross-application reference**: the source row's UUID with its
  application prefix (`projects/expense:{uuid}`), stored on the other side
  (`payment_requests.source_reference`, `expenses.finance_reference`). Never
  match on amounts or descriptions.
- **Authorization**: tokens vouch for organization and source application;
  the receiver re-checks that the organization has the module.
- **Ordering**: receivers return 503 (retry later) when an earlier fact is
  missing (e.g. `payment.completed` before `spending.accepted`), as Finance
  already does with `NotYetPostable`.

### 6.2 Projects → Finance (new unless marked)

| Event | Key | Payload (essentials) |
|---|---|---|
| `finance.spending.submitted.v1` | `expense:{id}:submitted:{revision}` | project code, grant ref, category, supplier, amount, currency, needed-by, approver, budget line |
| `finance.spending.cancelled.v1` | `expense:{id}:cancelled` | reason |
| `finance.advance.requested.v1` | `advance:{id}:requested` | person, amount, purpose |
| `finance.advance.retired.v1` | `advance-retirement:{id}` | lines (project, category, amount, receipt refs) |
| `finance.procurement.commitment.created.v1` / `.released.v1` | `po:{id}:created` / `po:{id}:released` | PO number, supplier, amount, project, grant |
| `finance.procurement.invoice_recorded.v1` | `po:{id}:invoice:{n}` | invoice ref, amount, due date |
| existing `finance.project_expense.paid/voided`, `grant.received`, `staff_advance.*`, `cash.*` | unchanged | kept for standalone and history |

### 6.3 Finance → Projects (none exist today)

| Event | Key | Effect in Projects |
|---|---|---|
| `projects.spending.accepted.v1` / `.rejected.v1` | `payment-request:{id}:accepted` / `:rejected` | state change, reason |
| `projects.payment.approved.v1` | `payment-request:{id}:approved` | state change |
| `projects.payment.completed.v1` | `payment-request:{id}:paid:{n}` | paid amount (partial allowed), date, reference, journal id |
| `projects.payment.failed.v1` / `.reversed.v1` | `…:failed:{n}` / `…:reversed:{n}` | state change, amounts |
| `projects.funding.receipt_confirmed.v1` | `receipt:{id}` | marks the grant disbursement confirmed |
| `projects.advance.issued.v1` | `payment-request:{id}:paid` (kind advance) | advance outstanding |

Requires a Projects inbox endpoint (the shared-module-kit `Inbox` already
used by Finance) and `projects` as an event module in `Envelope` (today the
module regex allows `finance|hr|payroll` only — a reviewed change to
`packages/shared-module-kit`).

### 6.4 Retries and failure handling

- Sender: outbox retries; `failed` rows surface in `projects:finance-sync`
  (Projects) and an equivalent Finance report.
- Receiver: never partially applies; one DB transaction per event.
- A request stuck in `awaiting_finance` longer than N days appears in the
  reconciliation report; nobody re-approves it in Projects.

### 6.5 Reconciliation requirements

Daily, per organization:
1. Every linked expense in `awaiting_finance` has a Finance payment request
   (by reference), else listed.
2. Every Finance `completed` payment with a Projects reference matches a
   Projects `paid` amount, else listed.
3. Standalone→linked switch: every Projects-paid expense has exactly one
   journal (via the existing `finance.project_expense.paid` key), else listed.
Differences are reported, never auto-corrected.

### 6.6 Implemented now (read-only, reversible)

`App\Support\FinanceSync` and `php artisan projects:finance-sync
[--organization=slug]`: counts of finance events by delivery status per
organization, and the events Finance refused or that are stuck (> 24 h),
with Finance's answer. Exit code 1 when something needs a person. It never
resends or edits. Test: `MultiPartyAccessTest` "reports money events
Finance refused…".

## 7. Existing payment records

No history is rewritten. Paid Projects expenses keep their status and their
already-posted journals. Open requests at switch-over are listed per
organization; an administrator chooses per batch to finish them on the old
path or send them to Finance. Petty cash keeps its handover command.

## 8. Stages (each reversible and reviewed before the next)

1. Read-only reconciliation (Projects side done; Finance side to build).
2. Inbox in Projects + `projects` event module + Finance → Projects status
   events, behind a per-organization switch `finance_payments` (off).
3. Linked mode for spending requests, pilot organization on staging.
4. Staff advances in linked mode.
5. Procurement commitment/payable split (own ADR, migration with rollback).
6. Payroll personnel-cost allocation (with the Payroll owner).

## 9. Not proposed

- Merging Finance or Payroll into Projects.
- Removing the standalone flow (organizations without Finance rely on it).
- A new general approval framework: Projects' approval policies and
  Finance's `PaymentRequests` already cover both sides.

## 10. Decisions for the reviewer

1. Finance as the only payer in linked mode (proposed) vs Projects payer
   with Finance as ledger.
2. Linked mode opt-in per organization after clean reconciliation
   (proposed) vs forced on subscription.
3. Approve adding `projects` as an event module in shared-module-kit and a
   Projects inbox.
4. Procurement split in the next milestone or later.
5. Whether standalone organizations may enter externally reported totals.
