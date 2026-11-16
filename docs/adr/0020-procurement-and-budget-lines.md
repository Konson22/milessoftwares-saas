# ADR-0020: Procurement against budget lines, receipts, supplier invoices and commitment release

Status: **Accepted; deployed to production 2026-11-16 (release 20261116210434).** Phase 4.1
corrections (section 5) are local, not deployed. Phase 4 of
Tatook Project Management. Builds on ADR-0017 (financial ownership, still
Proposed) without moving any ownership it leaves open.

## 1. Audit of what exists (verified in code, 2026-11-16)

**Lifecycle in place** (`App\Actions\Procurement`, `routes/web/procurement.php`):

- A purchase request is drafted with lines, then submitted. It is approved or rejected by a
  holder of `procurement.approve` who is not the requester.
- Quotes are added; fewer than 3, or not the lowest, needs a written reason.
- `raiseOrder` creates the purchase order **and** an `Expense` in status `requested`, through
  `Spending::request`.
- That expense is approved through `Spending::approve`:
  - by `finance.expenses.approve`, a different person;
  - with policy bands (`ApprovalPolicy`);
  - against the category's approved budget and the grant;
  - on the locked project.
  The approved expense **is** the commitment.
- Partial receipts exist; more than ordered is refused.
- One invoice reference per order.
- Payment needs everything received and an invoice recorded (`requirePayable`):
  - In standalone mode, Projects records the payment.
  - In integrated mode, `FinanceRequests::submitIfPayable` sends the spending to Finance only
    then. Finance pays, and its outcomes come back.

**`purchase_orders.expense_id` (NOT NULL, unique).** It does **not** create a recognised expense
when an order is approved.

- The `Expense` row is the spending request, then the commitment (`approved`), then the payment
  (`paid`).
- Reports count `approved` as committed and `paid` as paid (`ProjectFinance`).
- Recognised expenditure is Finance's: posted, in the provenance.
- The naming conflates things, but the states are distinct. Splitting the order from the expense
  is ADR-0017's "High" risk item (open requests, Finance references, audit history).
- **It is not done here.** The column stays as it is: no schema change, no data migration.

**Gaps found:**

| # | Gap | Effect |
|---|---|---|
| G1 | Requests and spending carry a cost **category** only, never a budget line | No exact budget reference; two lines of one category cannot be told apart |
| G2 | Approving a requisition checks no budget (the page shows a warning only) | Requisitions are approved with nothing left to spend |
| G3 | An order delivered short can never be paid (`requirePayable` wants everything received), and once anything arrived the commitment cannot be released | Commitment stuck; supplier never paid through the system |
| G4 | No rejected quantities, no receipt evidence | Rejected goods are invisible; acceptance not evidenced |
| G5 | One free-text invoice reference: no amount, currency, date of its own, document, or duplicate check | The same supplier invoice can be paid on two orders; an invoice below the order still pays the full order |
| G6 | Reports show committed and paid only | Supplier invoices awaiting payment (the liability Finance will book) cannot be seen |
| G7 | No per-line availability in budget revisions | A revision could take a line below what is committed on it |

**Not gaps (kept):**

- Separation of duties at every step.
- Project visibility (ADR-0016 scopes cover requests, lines, quotes, orders and receipts).
- Tenant isolation.
- Finance's ownership of payment in integrated mode.
- The single-commitment rule (budgets count an order once, through its expense).

## 2. Decisions

1. **Stable budget lines.** `budget_lines.line_key` identifies a line across versions.
   - Existing lines are backfilled with their own id.
   - A revision copies each line's key.
   - A new line gets a new key.
   - Requests and expenses store `budget_line_key`, nullable. Legacy rows keep null and count at
     category level only, exactly as before.
2. **Requisitions reference the approved budget.**
   - A request names a line of the project's approved version (`budget_line_id` on the form).
     The line's category must match the request's.
   - With no line named and exactly one approved line in the category, that line is used.
   - With several, the request must say which.
   - With none, or no approved budget, it cannot be submitted.
3. **Budget checks before authorising.** On the locked project, approving a requisition requires:
   - the line still in the approved version;
   - the estimate within both the **line's** available amount (line − committed − paid on that
     line) and the **category's**.
   Approving the order's spending (`Spending::approve`) re-checks the line as well as the
   category and grant, so two approvals cannot oversubscribe one line: both run on the locked
   project row.
   - A requisition does not reserve money (no pre-commitment). The commitment is created when
     the order's spending is approved, as before.
4. **Commitment lifecycle:**

   | Step | Commitment |
   |---|---|
   | Created | when the order's spending is approved |
   | Adjusted down | when the order is *closed* with what arrived (it becomes what was invoiced) |
   | Consumed | when paid (Projects records it in standalone mode; Finance reports it in integrated mode) |
   | Released | when cancelled before anything arrived (unchanged), or by closing short |

   Never adjusted up: more needs a new request.
5. **Receipts.**
   - Each receipt line records accepted and rejected quantities. Only accepted quantities count
     as received.
   - A receipt may carry one evidence file (private, behind `procurement.view` and project
     visibility).
   - Receipts are never edited.
6. **Supplier invoices** (`supplier_invoices`):
   - number, amount, currency, invoice date, optional due date, optional document;
   - one order, the order's supplier;
   - status `recorded` or `voided`.
   - **Duplicates refused:**
     - one live invoice per supplier and number (normalised: case and spaces), enforced by a
       partial unique index;
     - also checked against legacy `purchase_orders.invoice_reference` values of that supplier.
   - **Matching:**
     - the currency must be the project's;
     - live invoices together never exceed the order's commitment.
     - The order is **payable** when everything accepted has arrived, or it was closed, and live
       invoices equal the commitment.
     - Legacy orders, with a reference but no invoice rows, are treated as invoiced in full: they
       stay payable as before.
   - A recorded invoice can be voided with a reason until the spending is sent to Finance (or
     paid). The row stays.
   - `purchase_orders.invoice_reference` and `invoice_on` keep showing the first invoice, for
     older readers.
7. **Closing an order short.**
   - It needs at least one receipt, at least one live invoice, a reason, and nothing yet sent to
     Finance or paid.
   - The order's spending (the commitment) becomes the invoiced total. The difference is
     released to the budget line.
   - The order keeps its original amount. Who closed it, when, why, and from/to are audited.
8. **Finance.**
   - Nothing changes in the contract: `finance.spending.submitted` is still sent once, by
     `submitIfPayable`, now under the rule in 6.
   - The amount sent is the commitment, which after closing is the invoiced total.
   - No PaymentRequest is created here, and nothing is marked paid on an invoice.
9. **Reports.**
   - `ProjectFinance` adds `invoicedMinor`: the part of `committedMinor` already invoiced by
     suppliers and awaiting payment (the liability Finance books from Projects' request).
   - `committedMinor`, `paidMinor` and `availableMinor` keep their meaning, so partner report
     snapshots and donor views are unchanged.
   - The budget page shows each approved line with committed, paid and what is left on it.
   - Invoiced shows where it is acted on: on each order (ordered, committed, received,
     invoiced) and in Finance's commitments list (an Invoiced column per held commitment).
     A per-line invoiced figure was left out: it needs a three-table join for a number nobody
     acts on from the budget page.
   - The request form offers the approved lines of the chosen category, with what is left on
     each to those who may see budgets (`projects.finance.view`).
10. **Currency.**
    - Procurement is in the project's currency, the only currency its budget is in.
    - An invoice in another currency is refused.
    - No conversion, and no exchange rates (none exist in Projects; Finance owns them).
11. **Shorter path for small purchases.** Not a new mechanism: the existing rule already lets one
    quote stand with a written reason. Configurable thresholds are Phase 2.

## 3. Deferred (with reasons)

- **Splitting `purchase_orders.expense_id` from the expense** (ADR-0017 §5.3): high financial
  risk; G1–G7 are fixed without it.
  - Proposed for review: a nullable `purchase_orders.commitment_id` and a `commitments` table,
    backfilled one-to-one, read side first.
- **Procurement plans:**
  - Not in the MVP list.
  - Activities and milestones are not linked to budget lines today, and building that
    association is a planning feature.
- **Non-project purchasing.**
  - No product requirement found: every purchase is charged to a project budget.
  - `purchase_requests.project_id` stays required.
- **Partner and donor views of procurement.**
  - Today they see none of it (partner procurement capabilities are defined but not effective;
    donors have none). Default deny is kept.
  - Donors keep seeing the shareable budget figures (approved, committed, paid with provenance).
  - Opening procurement status to them needs the funding-agreement switches designed first.
- RFQs, bid evaluation, more procurement methods, amount thresholds per method, supplier
  performance: Phase 2.

## 4. Migration

- One additive migration, `2026_11_18_000100_procurement_lines_receipts_invoices`.
- It adds columns and a table, PostgreSQL CHECKs and the partial unique index, and backfills
  `budget_lines.line_key = id`.
- **No row is deleted. No financial amount is changed.**
- Rollback drops what it added, and only while nothing was recorded with it (section 5.6).

## 5. Phase 4.1: financial correctness (2026-11-16)

A review of the code as deployed. Three defects found and fixed. Each was reproduced by a test that
fails on the Phase 4 code and passes now.

### 5.1 Defects

| # | Severity | Defect | Reproduced by |
|---|---|---|---|
| D1 | High | Closing short set the commitment to the invoiced total, whatever had arrived. 4 of 10 pumps received with a 700.00 invoice on a 1,000.00 order closed at 700.00: 300.00 payable for goods that never came. | `closes short only down to the order price of what was accepted` |
| D2 | High | Closing short needed only *a* receipt. A delivery rejected in full let the order close on its invoice: 1,000.00 payable for nothing accepted. The same rule blocked `Spending::cancel`, so the commitment could not be released either. | `owes nothing for goods rejected in full` |
| D3 | High (integrated mode only, flag off in production) | When Finance approved or paid less than asked, the rest could never be paid, sent again or cancelled, yet every approval gate (category, budget line, grant, budget revision) still counted it. The reports (`ProjectFinance`) already released it, so the gates and the reports disagreed: 8,800.00 left for approvals, 9,200.00 reported. | `frees for new approvals what Finance approved short`; E2E scenario 11 |

### 5.2 Rules now

- **Value of what arrived.** An order has one price: the chosen quote's total. The request's line
  prices are the requester's estimates, not the supplier's. So Projects values accepted goods only
  when every line arrived in the same share: that share of the order's total, half up
  (`PurchaseOrder::acceptedValueMinor`). Lines arriving in different shares cannot be valued, and
  closing is refused with the reason. Per-line order prices are the way to lift that (next phase).
- **Closing short** needs accepted goods worth more than zero, and invoices no more than that value.
  An invoice above it is voided or the rest received.
  - Invoices *below* the value are the supplier billing less, for example a discount. The order
    closes, and the audit records `accepted_value_minor` and `below_accepted_minor` with the
    required reason.
  - Still refused, as before: once with Finance, once paid, or once closed.
- **Cancelling an order's spending** is refused once any goods were *accepted*. Rejected goods are
  not owed.
- **Settled short** (`Spending::settledShort`): approved spending whose latest Finance request is
  approved, part-paid, paid or failed counts as Finance's net paid plus outstanding authorized. This
  is the same as `ProjectFinance`.
  - Used by `categoryAvailable`, `chargedToGrant`, `Budgets::usedByLine`, the budget-revision guard,
    the budget page and the grant page.
  - Read-side only: the expense is not changed (Finance's outcomes never touch it).
  - Requests submitted or accepted, refused, reversed or cancelled release nothing in the gates.
    They can be sent again or cancelled in Projects, so the line is not promised twice.

### 5.3 What is verified and left as it is

- One commitment per order (`purchase_orders.expense_id`, unique). Approval commits, payment moves
  it to paid, and the budget is consumed once. Tested with a worked example: line 3,000.00, order
  1,000.00, 2,000.00 left before and after payment.
- Requisitions reserve nothing. The order's spending approval re-checks the line and the category on
  the locked project row, so two approved requisitions cannot both be ordered past the line.
- Part delivery stays committed in full and is not payable until received in full or closed. Partial
  supplier payment is **Phase 2**:
  - the contract sends one amount per request;
  - Finance may pay less than asked (scenario 11), but procurement has no milestones or retention.
- Multiple invoices make one request for their total, sent once (unit test, and E2E scenario 19 over
  real HTTP).
- No invoice is recorded or voided, and no order is closed, once a Finance request exists (unit and
  E2E).
- Finance outcomes: strict sequence, stale refused, gaps wait, duplicate transactions answered as
  duplicates, duplicate payment ids refused, amounts capped at what was asked and approved,
  correlation id kept across revisions (`FinanceIntegrationTest`, E2E 02, 06, 07–08, 10, 11, 13, 18).
- Budget revisions keep every line key and cannot drop or undercut a line with commitments (tested).
  Spending without a line key counts at category level, as before.

### 5.4 What "invoiced" means

`invoicedMinor` and the "Supplier invoices" columns are supplier invoices **recorded in Projects** as
procurement evidence:
- they are not a payable Finance has recognized;
- they are not a request awaiting Finance (that is the Finance status on the order).

The labels say so. Partner and donor figures (`shareable()`) do not carry it. `committedMinor` keeps
its established meaning: outstanding (approved, not yet paid). Paid is separate.

### 5.5 Limitations kept (documented, not built)

- After Finance **refuses** a procurement request, the order's invoices stay locked. The existing
  resubmission sends any amount up to what is left, without re-matching invoices. A wrong invoice is
  fixed in Finance's review or by cancelling the spending.
- The reports release refused spending at once (3C design). The approval gates hold it until it is
  resent or cancelled. The gates are the safer side, and changing the reports would change donor
  snapshots.
- A receipt entered twice by mistake (a double submit within what is still due) is two deliveries:
  nothing can tell it from a real second delivery. Over-receipt is refused under a row lock.

### 5.6 Migration and recovery

- The migration ran in production on 2026-11-16. It sorts after `2026_11_17_*` (Finance requests),
  which it needs. It must not be renamed: a renamed file would run again and fail on existing
  columns.
- `down()` now refuses when anything was recorded with it: invoices, evidence, rejections, closures,
  or spending charged to lines.
- Recovery is **forward only**: a corrective migration, and a restore from the pre-release backup
  only for a release with no new procurement records. Production had 0 budget lines, orders and
  invoices at deploy time.
