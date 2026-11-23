# 3. Roles, approvals and controls

## How people get access

Your organization's owner or administrator invites people to your Tatook workspace and gives each
person a **role**. The role decides what they see and what they can do. Buttons a person may not
use simply do not appear for them. Roles can be changed at any time.

## The roles

| Role | Usually given to | What they do |
| --- | --- | --- |
| **Finance Manager** | Director, head of finance | Sees the overall picture, sets budgets and **approves payments** |
| **Chief Accountant** | Senior accountant | Runs the accounts: sets them up, records and corrects entries, handles tax, closes months and years, **checks payments** |
| **Accountant** | Accountant, bookkeeper | Day-to-day work: invoices, bills, money received, bank checks, **checks payments** |
| **Finance Officer** | Finance or programme officer | **Asks for payments**, can keep petty cash, sees reports |
| **Cashier** | Cashier | Records money received, **makes approved payments**, can keep petty cash |
| **Auditor** | Internal or external auditor | **Sees everything, changes nothing** |
| **System Administrator** | IT or operations lead | **Sets the approval rules and limits**; sees the audit trail |

A small organization can give one person more than one role. Tatook will warn you when a
combination weakens your controls (see below), but it will not stop you.

### Who can do what

| Task | Finance Manager | Chief Accountant | Accountant | Finance Officer | Cashier | Auditor | System Admin |
| --- | :-: | :-: | :-: | :-: | :-: | :-: | :-: |
| See the accounts | ✓ | ✓ | ✓ | | | ✓ | ✓ |
| Change the list of accounts | | ✓ | | | | | |
| Record and correct journals | | ✓ | ✓ | | | | |
| Approve journals | | ✓ | | | | | |
| Record supplier bills | | ✓ | ✓ | | | | |
| Send invoices | | ✓ | ✓ | | | | |
| Record money received | | ✓ | ✓ | | ✓ | | |
| Check bank statements | | ✓ | ✓ | | | | |
| Ask for a payment | | | | ✓ | | | |
| Check a payment | | ✓ | ✓ | | | | |
| Approve a payment | ✓ | | | | | | |
| Make a payment | | | | | ✓ | | |
| Keep petty cash | | | | ✓ | ✓ | | |
| Set up petty cash funds | | ✓ | | | | | |
| Tax, currencies, fixed assets | | ✓ | | | | | |
| Set budgets | ✓ | | | | | | |
| See reports | ✓ | ✓ | ✓ | ✓ | | ✓ | |
| Close months and years | | ✓ | | | | | |
| Set approval rules | | | | | | | ✓ |
| See the audit trail | ✓ | ✓ | | | | ✓ | ✓ |

## Approval rules

The System Administrator sets these on the **Approval rules** page:

- **Payment approval limit** — payments from this amount up need approval. With no limit, every
  payment needs approval.
- **Checking step** — whether an accountant checks payments before the Finance Manager approves.
- **Journal approval** — whether journals need a second person's approval, and from what amount.
- **Petty cash limit** — the most one petty cash receipt can be.

Some protections are always on and cannot be switched off:

- **Nobody approves their own payment or journal** — owners included.
- **Every way money can leave the bank** goes through approval above the limit.
- **Saved entries are never edited**, only corrected with a new entry.

## Keeping duties separate

Good practice is that no single person can both spend money and hide it. Tatook warns you when one
person is given two jobs that should be separate:

| Should be different people | Why |
| --- | --- |
| Asking for a payment and approving it | Otherwise nobody checks the request |
| Approving a payment and making it | Otherwise one person controls the money alone |
| Asking for a payment and making it | Otherwise one person can pay themselves |
| Recording a supplier bill and approving its payment | Otherwise one person could invent a bill and pay it |
| Making payments and checking the bank statement | Otherwise a wrong payment could go unnoticed |
| Making payments and recording journals | Otherwise a payment could be hidden with an entry |
| Setting the approval rules and approving | Otherwise an approver could switch off their own check |
| Keeping petty cash and checking its receipts or counting it | Otherwise nobody checks the cash keeper |

The standard roles are already arranged so that no single role does two of these jobs.

## Audit trail

The Finance Manager, Chief Accountant, Auditor and System Administrator can see the audit trail:
who approved, rejected, paid, corrected, locked or changed what, and when.
