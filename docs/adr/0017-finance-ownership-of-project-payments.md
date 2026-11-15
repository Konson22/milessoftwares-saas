# ADR-0017: Who processes project payments when Finance is subscribed

Status: **Proposed — for independent review. Nothing in this ADR is
implemented.** No financial data is migrated until it is accepted.

## 1. What exists (inspected 2026-11-16)

| Area | Projects (`project-management`) | Finance (`finance-app`) |
|---|---|---|
| Budgets | `budget_versions` / `budget_lines`, approve → `projects.budget_minor` (`App\Actions\Budgets`) | Its own budgets per account/fund; does not read project budgets |
| Spending approval | `expenses`: request → approve (`finance.expenses.approve`, budget-left check in `Spending::approve`) → pay (`finance.payments.record`); or record already-paid "direct" spending | `PaymentRequests`: prepare → (review) → approve → disburse; one person never acts twice; disbursing posts through Payables/Banking |
| Payment recording | Projects marks the expense paid and emits `finance.project_expense.paid` (outbox, in the same transaction) | `PostEvent::projectExpensePaid` posts a journal from the event; it cannot refuse it |
| Voids | `finance.project_expense.voided` | `PostEvent::projectExpenseVoided` reverses |
| Petty cash | `PettyCash` boxes in Projects; `FinanceLink::keepsPettyCash` hands boxes to Finance when the organization has it (`projects:petty-cash-to-finance`, `finance.petty_cash.transferred`) | Imprest funds, vouchers, counts, replenishment through `PaymentRequests::replenish` |
| Staff advances | Issued and retired in Projects; `finance.staff_advance.issued/settled` | Posted from the events |
| Grants received | `grant_disbursements`; `finance.grant.received` | Posted from the event |
| Procurement | PR → quotes → PO; **the PO creates an expense request** (`purchase_orders.expense_id` unique, NOT NULL); payment blocked until goods fully received and an invoice reference recorded | Bills (`finance.bill.received`) from other products; not from Projects |
| Reconciliation | None | None for project events (no report comparing Projects' paid expenses with Finance journals) |

Consequences today, for an organization with both apps:
1. Two approval engines can approve the same outflow (Projects expense
   approval, then nothing stops Finance staff paying it again through a
   payment request). Nothing links them.
2. Finance learns of a project payment after the money left; it cannot hold,
   refuse or schedule it.
3. A PO is a commitment and a payable at once; there is no separate
   liability when goods arrive or invoice when billed.
4. Delivery is at-least-once: Finance must be idempotent per event; there is
   no report showing what Projects sent and Finance did not post.

## 2. Proposal

**One authoritative system for payment processing: Finance, when the
organization subscribes to it. Projects otherwise.** Projects stays the
system of record for *project budgets, commitments and their link to
activities and grants*; it stops being the system that approves and records
outflows once Finance is present. This mirrors what petty cash already does
(`FinanceLink::keepsPettyCash`).

### 2.1 With Finance (the "linked" mode)

- A project expense request in Projects is a **commitment request**: it is
  approved against the project budget in Projects (budget holder,
  `finance.expenses.approve`, budget-left check — unchanged).
- On approval Projects emits `finance.payment.requested` (new, versioned
  event) carrying the expense id as `source_id`, project, grant, category,
  supplier and amount. Finance creates a `PaymentRequest` of kind
  `project_expense` (prepared by the system, then reviewed/approved/disbursed
  under Finance's own segregation of duties and limits).
- Finance emits `finance.payment.disbursed` / `finance.payment.rejected`
  back (new inbound handler in Projects, idempotent on the request id).
  Projects marks the expense paid / returns it, and no longer emits
  `finance.project_expense.paid` for linked expenses.
- "Direct" already-paid spending is disabled in linked mode: Finance records
  it.
- Staff advances follow the same path (advance request in Projects →
  payment request in Finance → `disbursed` back); retirements stay in
  Projects and post as today.

### 2.2 Without Finance (the "standalone" mode)

Unchanged: Projects approves and records payments, keeps petty cash and
advances, and its budget-vs-paid reporting is the organization's project
financial reporting. It reports *paid amounts*, never "recognised
expenditure" (docs/finance/metric-definitions.md).

### 2.3 Commitments, liabilities, expenses and payments

| Stage | Record | Owner |
|---|---|---|
| Commitment | Approved expense request or purchase order (Projects) | Projects |
| Liability | Goods received / invoice recorded against the PO (Projects records the receipt; Finance records the bill in linked mode) | Projects (receipt), Finance (bill) |
| Expense (recognised) | Journal in Finance | Finance |
| Payment | Disbursed payment request (linked) / paid expense (standalone) | Finance (linked) / Projects (standalone) |

Procurement change (stage 4 below): a PO no longer *is* an expense request;
it creates a commitment row, and the payable is created on invoice. This
needs a migration of `purchase_orders.expense_id` and is the riskiest step.

### 2.4 Failures and duplicates

- Every new event carries a stable `transaction_id` (the source row id plus
  a step) and Finance stores processed event ids (it already rejects
  duplicates per envelope id; to be verified with a test before stage 2).
- Projects' inbound handler is idempotent on the payment request id and
  refuses a disbursement for an expense not in "awaiting payment".
- A nightly reconciliation report in Finance lists project expenses
  requested but not decided after N days, and paid in Projects but not
  posted (standalone→linked transition).
- If Finance is unreachable, the outbox retries (existing platform
  behaviour); the expense stays "awaiting payment" — money never moves on
  the Projects side in linked mode.

### 2.5 Existing payment records

- **No rewrite of history.** Expenses already paid in Projects stay paid in
  Projects; their journals already exist in Finance (posted from
  `finance.project_expense.paid`). A one-off reconciliation (stage 1)
  verifies, per organization, that every paid expense has exactly one
  journal; differences are reported, not auto-corrected.
- Open (requested/approved, unpaid) expenses at switch-over: listed for
  each organization; an administrator chooses per batch to finish them in
  Projects (old path) or send them to Finance as payment requests.
- Petty cash: the existing handover command stays the path.

### 2.6 Backward compatibility

- A per-organization switch `finance_payments` (off by default) selects the
  linked mode, flipped only after stage 1 reconciliation is clean.
- Old events (`finance.project_expense.paid/voided`) remain supported by
  Finance for standalone organizations and for history.

## 3. Stages (each reversible, each reviewed before the next)

1. **Reconciliation only (read-only).** Finance report: project events
   received vs posted; Projects report: paid expenses vs events sent.
   No behaviour change.
2. **Events and handlers behind the switch.** `finance.payment.requested`,
   inbound `disbursed/rejected`, idempotency tests on PostgreSQL. Switch off
   everywhere.
3. **Pilot one organization** in linked mode on staging, then production,
   with the reconciliation report watched daily.
4. **Procurement split** (commitment vs payable). Separate ADR before
   starting; migration of `purchase_orders.expense_id` with a rollback.
5. **Payroll**: Projects emits `payroll.timesheet.submitted` for time logged
   by staff on payroll, so personnel cost comes from payroll, not typed
   rates. Separate decision with the Payroll owner.

## 4. Not proposed

- Merging Finance or Payroll into Projects (or the reverse). Nothing found
  argues for it; the boundary is the problem, not the number of apps.
- Removing Projects' standalone payment flow: organizations without Finance
  rely on it.

## 5. Questions for the reviewer

1. Is Finance the authoritative payer in linked mode, as proposed, or should
   Projects remain payer with Finance as a read-only ledger?
2. Should linked mode be forced for every organization that subscribes to
   Finance, or opt-in per organization (proposed: opt-in, after a clean
   reconciliation)?
3. Is the procurement commitment/payable split in scope for the next
   milestone, or later?
