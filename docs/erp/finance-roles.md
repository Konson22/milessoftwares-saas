# Finance and accounting roles

Finance has one set of books and one application. Finance and Accounting are two sets of duties
over the same records, not two systems. Catalog version: 1.11.0
(`finance-app/platform/catalog.json`).

## How access is decided

- **Roles live in the catalog, and code checks permissions.** No PHP or TypeScript names a role.
  To change what a role may do, publish a new catalog version; no code changes.
- **Every action is authorized on the server**: a route permission first, then the rules in
  `App\Finance\PaymentRequests`, `PaymentPolicy` and `JournalApprovals`. Hiding a button is only
  a convenience.
- **Several roles give the union of their permissions**, still subject to the transaction rules
  below. The platform warns when one person is given a conflicting pair.
- **Organization owners hold every permission**, and the transaction rules apply to them too.
  No setting turns those rules off.
- **Users and role assignments are managed on the platform**, not in Finance. The System
  Administrator role here covers Finance's own setup only: the approval rules, plus reading the
  chart, the rules' history and the audit trail. It posts nothing and moves no money.
- **Every record belongs to one organization.** Queries are scoped to the signed-in organization
  and fail closed; the audit trail is scoped the same way.

## Roles

| Role | Duties |
| --- | --- |
| Finance Manager | Approves payments, manages budgets, reads the books, journals, chart, approval rules and audit trail |
| Finance Officer | Prepares payment requests; may keep a petty cash fund |
| Cashier | Disburses approved payments, records money in; may keep a petty cash fund |
| Chief Accountant | Runs the books: journals and their approval, reversals, periods, tax, assets, currencies, chart; sets up petty cash funds |
| Accountant | Prepares and posts journals, bills and receipts, reviews payments, reconciles |
| Auditor (read only) | Reads everything, approval rules and audit trail included; changes nothing |
| System Administrator | Sets the approval rules; administration dashboard |

Bookkeeper was retired in 1.10.0, together with `payables.manage` and `banking.manage`, the only
permissions that paid or moved money without a request. Project management's own Bookkeeper role is
a different application's role and is unchanged.

## Role and permission matrix

Permissions are shown without the `finance.` prefix.

| Permission | Finance Manager | Finance Officer | Cashier | Chief Accountant | Accountant | Auditor | System Admin |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `accounts.view` | ✓ | | | ✓ | ✓ | ✓ | ✓ |
| `accounts.manage` | | | | ✓ | | | |
| `journals.view` | ✓ | | | ✓ | ✓ | ✓ | |
| `journals.prepare` | | | | ✓ | ✓ | | |
| `journals.post` | | | | ✓ | ✓ | | |
| `journals.approve` | | | | ✓ | | | |
| `journals.reverse` | | | | ✓ | | | |
| `payables.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | |
| `bills.manage` | | | | ✓ | ✓ | | |
| `receivables.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | |
| `receivables.manage` | | | | ✓ | ✓ | | |
| `receipts.record` | | | ✓ | ✓ | ✓ | | |
| `banking.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | |
| `banking.reconcile` | | | | ✓ | ✓ | | |
| `payments.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | |
| `payments.prepare` | | ✓ | | | | | |
| `payments.review` | | | | ✓ | ✓ | | |
| `payments.approve` | ✓ | | | | | | |
| `payments.disburse` | | | ✓ | | | | |
| `tax.manage` | | | | ✓ | | | |
| `currencies.manage` | | | | ✓ | | | |
| `budgets.manage` | ✓ | | | | | | |
| `assets.manage` | | | | ✓ | | | |
| `periods.manage` | | | | ✓ | | | |
| `reports.view` | ✓ | ✓ | | ✓ | ✓ | ✓ | |
| `approvals.view` | ✓ | | | ✓ | | ✓ | ✓ |
| `approvals.configure` | | | | | | | ✓ |
| `audit.view` | ✓ | | | ✓ | | ✓ | ✓ |
| `dashboard.finance` | ✓ | ✓ | ✓ | | | ✓ | |
| `dashboard.accounting` | | | | ✓ | ✓ | ✓ | |
| `dashboard.admin` | | | | | | | ✓ |
| `pettycash.record` | | ✓ | ✓ | | | | |
| `pettycash.manage` | | | | ✓ | | | |

New in 1.10.0: `approvals.view` (read the rules and their history), `audit.view` (read the audit
trail) and `dashboard.admin`. Finance Manager gains `accounts.view`. System Administrator loses
`accounts.manage`: changing the chart is an accounting duty. Finance Manager does not get
`journals.approve`; that would pair with `payments.approve`.

## Duties

| Duty | Permission | Default holder |
| --- | --- | --- |
| Prepare a payment | `payments.prepare` | Finance Officer |
| Review a payment | `payments.review` | Accountant, Chief Accountant |
| Approve a payment | `payments.approve` | Finance Manager |
| Disburse a payment | `payments.disburse` | Cashier |
| Record a bill or supplier | `bills.manage` | Accountant, Chief Accountant |
| Record money in | `receipts.record` | Cashier, Accountant, Chief Accountant |
| Prepare a journal | `journals.prepare` | Accountant, Chief Accountant |
| Post a journal | `journals.post` (direct), `journals.approve` (a held one) | Accountant, Chief Accountant (`journals.approve`: Chief only) |
| Reconcile accounts | `banking.reconcile` | Accountant, Chief Accountant |
| Close accounting periods | `periods.manage` | Chief Accountant |
| Reverse a posted journal | `journals.reverse` | Chief Accountant |
| Set approval rules | `approvals.configure` | System Administrator |
| Keep a petty cash fund and pay from it | `pettycash.record` (and being its custodian) | Cashier, Finance Officer |
| Set up, hand over, suspend, close a fund | `pettycash.manage` | Chief Accountant |
| Check petty cash vouchers | `payments.review` (not the custodian) | Accountant, Chief Accountant |
| Count petty cash | `banking.reconcile` (not the custodian) | Accountant, Chief Accountant |

## Payment requests: the only way money is paid out

A request is a supplier payment, an expense, an employee advance or a transfer between accounts.
It moves no balance until it is disbursed, when it posts through `Payables` or `Banking` like any
other payment, so reports, the ledger and budget-versus-actual all read the same entry. The pay
buttons on bills, suppliers and bank accounts open a new request; there is no direct route left
(`POST supplier-payments`, `banking/money-out` and `banking/transfers` are gone).

```
prepared -> [in review] -> waiting for approval -> ready to disburse -> disbursed
                 \                  \                     \
                  rejected or withdrawn (a closed request moves no balance)
```

### Rules an organization sets

A holder of `approvals.configure` sets them; holders of `approvals.view` read them, read only.

| Rule | Default | Effect |
| --- | --- | --- |
| Approval limit | None | Payments below it need no review or approval. None: every payment needs approval |
| Review before approval | Off | Adds the review step |
| Journal approval, and its limit | Off | Holds manual journals for a second person |

### Always on

These cannot be changed by any setting, and apply to owners too:

- **Separation per request.** On a request that needs approval, nobody reviews or approves what
  they prepared, approves what they reviewed, or disburses what they prepared, reviewed or
  approved. Below the limit there is no approver, and whoever prepared it may disburse it.
- Nobody rejects their own request (they withdraw it), steps cannot be skipped, a request is
  disbursed only once, and a request the books refuse stays ready to disburse.
- Each request and held journal keeps a snapshot of the rules it was raised under (`policy`), so
  a later change to the rules is visible against it.
- Nobody approves their own journal.

### Other ways money could leave, from the limit up

| Route | What happens |
| --- | --- |
| `POST journals`, crediting a bank or cash account | Becomes a payment request, after journal approval if that applies too. It posts when disbursed |
| `POST journals/{journal}/reverse`, of a journal that debited a bank or cash account | Becomes a payment request. The journal is reversed when it is disbursed |
| `POST tax-returns/{return}/settle`, for tax owed | Becomes a payment request. The return is settled when it is disbursed |
| Recurring journal crediting a bank or cash account, or debiting one and reversing itself | Does not post until a holder of `payments.approve` approves it. A journal approver's approval does not count. Each run then posts without a further request |
| `DELETE tax-returns/{return}`, when that takes a settled refund back out of the bank | Refused |
| `POST reconciliation/lines/{line}/enter`, outgoing | Refused; raise a request and match the line. Bank rules leave such a line unmatched |

With no limit set (the default), "from the limit up" means every amount.

### Not held

- **Below the approval limit**, the routes above.
- **Refunds when an invoice is voided**, and refunds from products.
- **Opening balances** (`periods.manage`) and **products' business events**.

## Petty cash

An imprest fund in the one ledger (`AppFinancePettyCash`). There is no second balance: a fund is a
petty cash account of the chart, and what it holds is that account's balance.

| Step | Who | What happens |
| --- | --- | --- |
| Set up | `pettycash.manage` | Name, petty cash account (one fund each), custodian, float, optional top-up level. The custodian must hold `pettycash.record` (checked on the platform). Holds nothing yet |
| Fund and top up | The custodian, or `pettycash.manage` | A transfer payment request from a bank in the fund's currency, for the float less what it holds. Approved and disbursed like any payment. One open at a time; not while any voucher is unchecked |
| Pay | The custodian only, with `pettycash.record` | A voucher with its receipt (photo or PDF, 5 MB), up to the organization's petty cash limit and never more than the fund holds. Posts at once: Dr the expense (or Employee advances), Cr the fund, with cost centre and project |
| Check | `payments.review`, not the custodian or recorder | Verify, or reject: the voucher is reversed and the custodian owes the fund |
| Count | `banking.reconcile`, not the custodian | Cash counted against the books. A difference needs an explanation and posts to Cash over and short |
| Hand over | `pettycash.manage` | Only after a count later than the last voucher, to someone who may keep petty cash |
| Close | `pettycash.manage` | Only once the cash has gone back (a transfer request), every voucher is checked and no request is open |

The expense is booked when the voucher is recorded (accrual), and a top-up is a transfer, so nothing is
expensed twice. Vouchers a top-up paid back are marked with it when it is disbursed. A fund can be
suspended (no vouchers) and reactivated. The petty cash limit per voucher is set with the approval
rules (`approvals.configure`); until it is set, no voucher can be recorded.

**Cash never goes below nothing.** Payment requests paid from a cash or petty cash account are refused
at disbursement if the account would go negative (they stay ready to disburse). Bank accounts may
still go overdrawn.

**Boxes moved in from Projects.** An organization with Finance keeps its petty cash here. Projects
(project management) stops opening boxes or paying from its own, and links to this page instead. Each
box is moved by a Projects manager ("Move to Finance") or by `php artisan petty-cash:move-to-finance`
in project-management (`--dry-run` lists first). Projects sends `finance.petty_cash.transferred`.
Finance then:

- opens a petty cash account (the first free code from 1041 to 1099);
- opens an active fund at the same float, with **no custodian** until a Chief Accountant counts the
  cash and names one;
- posts what the box held: Dr the new account / Cr Cash (where Projects' till events had put it).

This is audited as `petty_cash.fund_moved_in`. A box with a top-up waiting, or one that is overdrawn,
is not moved until that is sorted out.

Organizations without Finance keep Projects' own petty cash. Vouchers charged to a project here are
**not** fed back to that project's budget in Projects yet.

A box in a currency other than the books' waits for an exchange rate before it moves. Its earlier till
events were refused as `currency_mismatch`, so check the Cash account after moving such a box.

## Journals

Someone holding only `journals.prepare` never posts: their entry always waits for approval.
Someone holding `journals.post` posts directly unless the organization's journal rule or the bank
rule above holds it. Approving posts the journal, unless it takes money out of a bank from the
payment limit up: then it goes on to payment approval and disbursement.

## Typed exchange rates

A rate typed on a payment never lowers the amount compared with the approval limit: the higher of
the typed rate and the rate on record counts. A typed rate more than twice or less than half the
rate on record is refused. With no rate on record, a typed rate always needs approval. Transfers
post at the day's rates, so a rate typed on one is ignored.

## Audit trail

`audit_events` in Finance records the control events, each with the organization, the person, the
time, the action, the record, and the values before and after:

| Area | Actions |
| --- | --- |
| Payment requests | `payment_request.prepared`, `.reviewed`, `.approved` (with the terms approved), `.disbursed`, `.rejected`, `.withdrawn` |
| Journals | `journal.posted` (manual), `journal.reversed`, `journal_draft.submitted`, `.approved`, `.rejected`, `.withdrawn`, `recurring_journal.approved` |
| Approval rules | `approval_rules.payments_changed`, `approval_rules.journals_changed` |
| Periods | `period.year_added`, `.locked`, `.unlocked`, `.year_closed`, `.year_reopened` |
| Chart | `account.added`, `account.changed` (changed fields only, bank details included) |
| Petty cash | `petty_cash.fund_created`, `.fund_changed`, `.custodian_changed`, `.fund_closed`, `.voucher_recorded`, `.voucher_verified`, `.voucher_rejected`, `.replenishment_requested`, `.counted`, `.fund_moved_in`; the limit as `approval_rules.petty_cash_changed` |

Events are written in the same transaction as the change, cannot be updated or deleted (the model
refuses), and have no routes that change them. `audit.view` opens **Audit trail**, filtered by
period and area. The Approval rules page shows the rules' last 20 changes.

Role assignments are audited on the platform (central `audit_logs`, hash-chained), not in Finance.

## Dashboards and menu

One dashboard page draws its views from the ledger and documents; nothing is stored for them.

- `dashboard.finance`: cash and bank, payments to approve, pending expense requests, obligations,
  cash flow, performance, budget utilization, departmental expenditure.
- `dashboard.accounting`: pending journals, unposted transactions, bank and petty cash
  reconciliation, trial balance, ledger activity, payables, receivables, employee advances,
  period-end tasks.
- `dashboard.admin`: the approval rules in force, the chart's shape (accounts, banks, cost
  centres), financial years and the last locked month, and the latest control events. No
  balances and no transactions.
- Holding several gives a switch between them. Holding none gives the general overview.
- Each figure also needs the permission for its source, so a Cashier gets the finance dashboard
  without the budget or the statements.

Definitions used:

| Figure | Means |
| --- | --- |
| Departmental expenditure | Expense account lines per cost centre, this month |
| Budget utilization | Spending against the current year's first budget, year to date |
| Pending expense requests | Expense and advance requests in review or waiting for approval |
| Unposted transactions | Requests ready to disburse, and recurring journals that have fallen due |
| Employee advances | Balance of the Employee advances account; settle them with a journal or money in |
| Petty cash | Accounts of the new Petty cash kind, reconciled with the usual statements |

The menu shows exactly what the member's permissions open; nothing is hidden by role or dashboard.
A Finance Manager therefore finds journals, recurring journals, the chart and cost centres.

## Conflicting permissions

The platform warns when these pairs are assigned to one person. No role in the catalog holds both
sides of a pair (a test keeps it that way). The transaction rules above apply whatever is assigned.

| Permission | Conflicts with | Why |
| --- | --- | --- |
| `payments.prepare` | `payments.approve` | One person both requesting and approving payments removes the second check. |
| `payments.review` | `payments.approve` | Review and approval are meant to be two different checks. |
| `payments.approve` | `payments.disburse` | One person both approving and releasing money removes the second check. |
| `payments.prepare` | `payments.disburse` | One person both requesting and releasing money should not handle the same payment. |
| `bills.manage` | `payments.approve` | Recording a bill and approving its payment lets one person create and clear a debt. |
| `payments.disburse` | `banking.reconcile` | Whoever releases money should not also reconcile the account it left. |
| `payments.disburse` | `journals.post` | Whoever releases money should not also be able to post entries that hide it. |
| `payments.approve` | `journals.approve` | One person approving both payments and journals controls both sides of an entry. |
| `approvals.configure` | `payments.approve` | An approver who sets the approval rules can switch off the check on themselves. |
| `approvals.configure` | `journals.approve` | An approver who sets the approval rules can switch off the check on themselves. |
| `pettycash.record` | `banking.reconcile` | Whoever keeps petty cash should not also count and reconcile it. |
| `pettycash.record` | `payments.review` | Whoever keeps petty cash should not also check its vouchers. |

## Rolling out catalog 1.11.0

Adds `pettycash.record` (Cashier, Finance Officer) and `pettycash.manage` (Chief Accountant). After
publishing, run `php artisan platform:catalog-reassign finance` (dry run first) so existing holders
get them. Finance's migration adds the petty cash tables and the limit. Then set the petty cash limit
in Approval rules.

Then move Projects' boxes:

1. Deploy Finance before Projects.
2. Run Projects' migration.
3. Run `php artisan petty-cash:move-to-finance --dry-run`, then run it again without `--dry-run`.
4. Count each moved fund and name its custodian.

## Rolling out catalog 1.10.0

Behaviour changes for every organization as soon as Finance is deployed, before any role moves:

- Direct supplier payments, money out and transfers are gone; everything goes through a request.
- "Hold direct payments" and "Keep duties separate" are gone: from the limit up, bank outflows are
  always held, and separation is always on. With no limit set, every outflow needs approval, so
  each organization needs someone who approves and someone who disburses, or a limit.

Steps, in order:

1. Deploy Finance and run its migration (`audit_events`, `policy` columns, drops the two old
   workspace switches).
2. Publish catalog 1.10.0 to central.
3. Find and revoke the Bookkeepers. Read the dry run first; it lists each holder and what the role
   gave them. By default nobody is given anything in its place:

   ```
   php artisan platform:catalog-retire-role finance bookkeeper --dry-run
   php artisan platform:catalog-retire-role finance bookkeeper
   ```

   To give former Bookkeepers a role instead, name it explicitly, for example `--map=accountant`.
   The mapped role is checked against separation of duties like any assignment: a blocking pair
   leaves the member revoked without it, and the output says so. Each revocation and replacement is
   audited (`role.retired`, `role.replaced_after_retirement`) and announced to Finance
   (`access.changed`), which drops the member's cached access. The command fails if any assignment
   of the role is still live afterwards, and can be run again safely.
4. Move the other holders to 1.10.0, checking the dry run first:

   ```
   php artisan platform:catalog-reassign finance --dry-run
   php artisan platform:catalog-reassign finance
   ```

   Until they are moved, holders keep their old version's permissions. An old System Administrator
   keeps `accounts.manage` and lacks `approvals.view`, `audit.view` and the admin dashboard; old
   Finance Managers, Chief Accountants and Auditors lack the new read permissions.
5. Check: no live assignment points at a Bookkeeper role (step 3 checks this), and each organization
   has an approver and a disburser or a limit.

Rollback: redeploy the previous Finance and run its migration's `down` (it restores the two
switches, with their defaults; audit events are dropped). Each `role.retired` audit row on central
holds the revoked assignment's id, scope, expiry and grantor, so an assignment can be restored by
clearing its `revoked_at`.

## Later

- Routing approval to named people or by amount bands; a single approval level per organization today.
- Exporting the audit trail, and auditing more areas (bills, receipts, tax codes, budgets).
- Per-employee advance tracking with settlement; today it is one account's balance.
- Requests raised by staff outside Finance.
- Payroll advances from HR (roadmap H6) posting to the same account.
