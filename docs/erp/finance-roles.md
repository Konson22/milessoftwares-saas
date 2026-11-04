# Finance and accounting roles

Finance has one set of books and one application. Finance and Accounting are two sets of duties
over the same records, not two systems. Catalog version: 1.9.0
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
  They can, however, turn separation off, because that is the organization's policy.
- **Users and role assignments are managed on the platform**, not in Finance. The System
  Administrator role here covers Finance's own setup only: approval rules and the chart of accounts.

## Role and permission matrix

Permissions are shown without the `finance.` prefix.

| Permission | Finance Manager | Finance Officer | Cashier | Chief Accountant | Accountant | Auditor | System Admin | Bookkeeper |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `accounts.view` | | | | ✓ | ✓ | ✓ | ✓ | ✓ |
| `accounts.manage` | | | | ✓ | | | ✓ | |
| `journals.view` | ✓ | | | ✓ | ✓ | ✓ | | ✓ |
| `journals.post` | | | | ✓ | ✓ | | | |
| `journals.approve` | | | | ✓ | | | | |
| `payables.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | | ✓ |
| `payables.manage` | | | | | | | | ✓ |
| `receivables.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | | ✓ |
| `receivables.manage` | | | | ✓ | ✓ | | | ✓ |
| `banking.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | | ✓ |
| `banking.manage` | | | | | | | | ✓ |
| `banking.reconcile` | | | | ✓ | ✓ | | | ✓ |
| `tax.manage` | | | | ✓ | | | | |
| `currencies.manage` | | | | ✓ | | | | |
| `budgets.manage` | ✓ | | | | | | | |
| `assets.manage` | | | | ✓ | | | | |
| `reports.view` | ✓ | ✓ | | ✓ | ✓ | ✓ | | |
| `periods.manage` | | | | ✓ | | | | |
| `dashboard.finance` | ✓ | ✓ | ✓ | | | ✓ | | |
| `dashboard.accounting` | | | | ✓ | ✓ | ✓ | | |
| `journals.prepare` | | | | ✓ | ✓ | | | |
| `journals.reverse` | | | | ✓ | | | | |
| `bills.manage` | | | | ✓ | ✓ | | | |
| `receipts.record` | | | ✓ | ✓ | ✓ | | | |
| `payments.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | | |
| `payments.prepare` | | ✓ | | | | | | |
| `payments.review` | | | | ✓ | ✓ | | | |
| `payments.approve` | ✓ | | | | | | | |
| `payments.disburse` | | | ✓ | | | | | |
| `approvals.configure` | | | | | | | ✓ | |

Finance roles: Finance Manager, Finance Officer, Cashier. Accounting roles: Chief Accountant,
Accountant. Cross-functional: Auditor, System Administrator.

Bookkeeper is kept for small teams and for existing assignments. It holds the older direct
`payables.manage` and `banking.manage` permissions, so it pays without a request unless approval
holds the payment.

## Duties

| Duty | Permission | Default holder |
| --- | --- | --- |
| Prepare a payment | `payments.prepare` | Finance Officer |
| Review a payment | `payments.review` | Accountant, Chief Accountant |
| Approve a payment | `payments.approve` | Finance Manager |
| Disburse a payment | `payments.disburse` | Cashier |
| Prepare a journal | `journals.prepare` | Accountant, Chief Accountant |
| Post a journal | `journals.post` (direct), `journals.approve` (a held one) | Accountant, Chief Accountant (`journals.approve`: Chief only) |
| Reconcile accounts | `banking.reconcile` | Accountant, Chief Accountant |
| Close accounting periods | `periods.manage` | Chief Accountant |
| Reverse a posted journal | `journals.reverse` | Chief Accountant |
| Set approval rules | `approvals.configure` | System Administrator |

## Payment requests

A request is a supplier payment, an expense, an employee advance or a transfer between accounts.
It moves no balance until it is disbursed, when it posts through `Payables` or `Banking` like any
other payment, so reports, the ledger and budget-versus-actual all read the same entry.

```
prepared -> [in review] -> waiting for approval -> ready to disburse -> disbursed
                 \                  \                     \
                  rejected or withdrawn (a closed request moves no balance)
```

The approval rules are set per organization, by a holder of `approvals.configure`:

| Rule | Default | Effect |
| --- | --- | --- |
| Hold direct payments | Off | Also holds the ways of paying without a request (below) |
| Approval limit | None | Smaller payments need no review or approval. None: every payment needs approval |
| Review before approval | Off | Adds the review step |
| Keep duties separate | On | Nobody reviews or approves what they prepared, approves what they reviewed, or disburses what they prepared, reviewed or approved |

Whatever the rules say, nobody rejects their own request (they withdraw it), steps cannot be
skipped, a request is disbursed only once, and a request the books refuse stays ready to disburse.

### Ways of paying that approval holds

With **Hold direct payments** on, from the limit up:

| Route | What happens |
| --- | --- |
| `POST supplier-payments` | Becomes a payment request |
| `POST banking/money-out` | Becomes a payment request |
| `POST banking/transfers` | Becomes a payment request |
| `POST journals`, crediting a bank or cash account | Becomes a payment request, after journal approval if that applies too. It posts when disbursed |
| `POST journals/{journal}/reverse`, of a journal that debited a bank or cash account | Becomes a payment request. The journal is reversed when it is disbursed |
| `POST tax-returns/{return}/settle`, for tax owed | Becomes a payment request. The return is settled when it is disbursed |
| Recurring journal crediting a bank or cash account, or debiting one and reversing itself | Does not post until a holder of `payments.approve` approves it. A journal approver's approval does not count. Each run then posts without a further request |
| `DELETE tax-returns/{return}`, when that takes a settled refund back out of the bank | Refused while the hold applies |
| `POST reconciliation/lines/{line}/enter`, outgoing | Refused; raise a request and match the line. Bank rules leave such a line unmatched |

### Not held

These move bank or cash without a payment request:

- **Below the approval limit**, every way of paying above.
- **Refunds when an invoice is voided**, and refunds from products.
- **Opening balances** (`periods.manage`) and **products' business events**.
- **Everything direct while Hold direct payments is off**, for holders of `payables.manage` or
  `banking.manage`. No role other than Bookkeeper holds them.

## Journals

Someone holding only `journals.prepare` never posts: their entry always waits for approval.
Someone holding `journals.post` posts directly unless the organization's journal rule or the bank
rule above holds it. Nobody approves their own entry, owners included, and this cannot be turned
off. Approving posts the journal, unless it takes money out of a bank while payments are held: then
it goes on to payment approval and disbursement.

## Typed exchange rates

A rate typed on a payment never lowers the amount compared with the approval limit: the higher of
the typed rate and the rate on record counts. A typed rate more than twice or less than half the
rate on record is refused. With no rate on record, a typed rate always needs approval. Transfers
post at the day's rates, so a rate typed on one is ignored.

## Dashboards and menu

One dashboard page draws both views from the ledger and documents; nothing is stored for them.

- `dashboard.finance`: cash and bank, payments to approve, pending expense requests, obligations,
  cash flow, performance, budget utilization, departmental expenditure.
- `dashboard.accounting`: pending journals, unposted transactions, bank and petty cash
  reconciliation, trial balance, ledger activity, payables, receivables, employee advances,
  period-end tasks.
- Holding both gives a switch between them. Holding neither gives the general overview.
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

The menu hides Accounting, Fixed assets and Tax from members with only the finance dashboard, and
Planning from members with only the accounting dashboard. This only tidies the menu; permissions
decide what they may open. Payment requests, payables, receivables, banking and reports are shared.

## Conflicting permissions

The platform warns when these pairs are assigned to one person. No role in the catalog holds both
sides of a pair (a test keeps it that way).

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

## Rolling out catalog 1.9.0

Role assignments keep the permissions of the catalog version they were made under, so **publishing
this version does not change existing holders**. To move them, run this on central after publishing,
checking the dry run first:

```
php artisan platform:catalog-reassign finance --dry-run
php artisan platform:catalog-reassign finance
```

It gives each live holder the role of the same key in the current version, and audits the move. It
leaves roles the new version does not have and reports them. Until holders are moved:

- An existing Accountant keeps direct paying, posting and approving, but **loses reversing journals
  and changing journal approval rules**, which now need `journals.reverse` and
  `approvals.configure`. Owners keep both.
- Existing holders cannot act on payment requests beyond withdrawing their own.

Moving an existing Accountant does not give back reversing or the approval rules: in this version
those belong to Chief Accountant and System Administrator. Decide who holds them before moving.

Turn on **Hold direct payments** only once someone can approve and someone can disburse.

## Later

- Routing approval to named people or by amount bands; a single approval level per organization today.
- Per-employee advance tracking with settlement; today it is one account's balance.
- Requests raised by staff outside Finance.
- Payroll advances from HR (roadmap H6) posting to the same account.
