# 2. Features

This chapter goes through each part of Tatook Finance. What each person can see and do depends on
their role (see [Roles, approvals and controls](03-roles-and-controls.md)). Accounting words are
explained in [Words explained](08-glossary.md).

---

## Accounts (chart of accounts)

Your **accounts** are the categories that every amount is recorded under: "Sales", "Rent",
"Salaries", "Bank – Equity", "Cash in hand", "What customers owe us", and so on. The full list is
called the **chart of accounts**.

- You start with a ready-made list that suits most organizations.
- Rename accounts, add your own, and switch off the ones you do not use.
- Each bank account, cash box, mobile money wallet and card account is an account of its own, so you
  always see its balance.
- Click any account to see its balance and every amount recorded on it.

## Projects and departments

You can label any amount with a **project** (for example a donor-funded project) or a **cost
centre** (a department or branch). Later you can see income and spending for each one.

- Use them on invoices, bills, payments and budgets.
- You can make a label compulsory on certain accounts, so nothing is recorded without it.
- Reports such as profit and loss can be shown per project or per department.

## Journals

A **journal** is a single entry in your accounts. Most journals are created for you — when you send
an invoice, pay a bill or make a transfer. You only write a journal yourself for adjustments and
corrections.

- Every journal gets a number (JV-000001, JV-000002, ...), with no gaps.
- **Correcting a mistake:** a saved journal cannot be changed. Click **Reverse** to cancel it with
  an opposite entry, then enter the correct one.
- **Second pair of eyes (optional):** you can decide that journals above a certain amount must be
  approved by another person before they count.

### Repeating journals

For entries that happen every month, such as rent or monthly charges, you set the entry up once with
a start date and how often it repeats. Tatook records it on time every month. If something stops it
(for example the month is already closed), it pauses and tells you why.

## Customers and invoices

- Create **invoices** for your customers, with tax added automatically.
- Record **payments** from customers — cash, bank transfer, mobile money or card. Part payments are
  fine.
- **Cancel (void)** an invoice that was a mistake; if the customer had paid, the refund is recorded.
- See **who owes you money** and for how long (up to 30, 60, 90 days or more).
- Sales from Tatook shop applications arrive automatically (see
  [Connected applications](06-connected-applications.md)).

## Suppliers and bills

- Keep a **list of suppliers**, with what you owe each one.
- Record **bills** from suppliers, with tax.
- Pay bills through a **payment request**, so every supplier payment is approved.
- Record **credit notes** when a supplier gives you money back.
- See **who you owe** and for how long.

## Payment requests

This is how money leaves your accounts safely. Every payment follows these steps, each done by a
different person:

1. **Request** — a finance officer asks for the payment: who is paid, how much, from which account,
   and what it is for.
2. **Check** (optional) — an accountant checks that the request is correct.
3. **Approve** — the Finance Manager approves or rejects it.
4. **Pay** — the cashier makes the payment. Only now is the money recorded as gone.

Good to know:

- You set an **approval limit**. Payments below it can go straight to the cashier; payments from the
  limit up must be approved. With no limit, every payment needs approval.
- **Nobody handles the same payment twice.** The person who asked for it cannot approve it or pay
  it. This applies to the owner too.
- A request is never edited. To change it, withdraw it and make a new one.
- Every other way money could leave — paying tax, a journal that takes money from the bank — goes
  through the same approval.

## Banking

The **Banking** page shows every bank, cash, mobile money, card and petty cash account with its
balance.

- **Money in:** record money received that is not an invoice payment (a grant, interest, a refund).
- **Money out:** done through a payment request.
- **Transfers:** move money between your own accounts, even between currencies.

## Checking your bank statement (reconciliation)

Each month, compare your bank statement with your records to make sure they agree:

1. Download your statement from your bank as a CSV file (most online banking can do this) and
   upload it.
2. Tatook matches most lines automatically.
3. Match the rest by hand, or record lines that are only on the statement (such as bank charges).
4. When everything matches and the balances agree, the statement is marked **reconciled**.

Tip: set up **bank rules** such as "description contains *SMS charge* → record as bank charges", and
Tatook will handle those lines for you every month.

## Petty cash

For small cash spending (transport, tea, small supplies). It works like this:

- A **fund** has a fixed amount (for example USD 200) and one **custodian** who keeps the cash.
- The custodian pays small expenses and records each one with its **receipt** (a photo or PDF), up to a limit
  you set.
- **Someone else checks** each receipt. A rejected receipt must be paid back by the custodian.
- **Someone else counts** the cash from time to time. Any difference is recorded with an
  explanation.
- When the cash runs low, the fund is **topped up** back to its fixed amount through an approved
  payment request.

## Tax

- Set up your **tax rates** (for sales and for purchases).
- Tax is worked out automatically on invoices and bills.
- A **tax return** shows, for a period, the tax you collected, the tax you paid, and what you owe the
  tax office. You can download it to Excel.
- Once a return is **filed**, that period is locked for tax so the figures cannot change.
- **Pay** the tax office through payment approval, or record a refund.

## Budgets

- Plan your income and spending for the year, **month by month, per account**.
- Start from zero, or **copy last year's figures** and raise or lower them by a percentage.
- Make a budget for the whole organization or for one department.
- The **budget against actual** report shows where you are over or under budget.

## Fixed assets

*Available on plans that include fixed assets.*

Fixed assets are things you keep for years, such as vehicles, computers and furniture.

- Keep a **register** of your assets: what they cost and how long they last.
- Each month, Tatook records how much value they lose (**depreciation**) with one click.
- When you sell or throw away an asset, Tatook records the gain or loss.

## More than one currency

*Available on plans that include multiple currencies.*

- Hold bank accounts, send invoices, record bills and make payments in **other currencies**.
- Enter the **exchange rate** for the day; it is used until you enter a new one.
- Your reports are always in your main currency, and Tatook records any gain or loss from rate
  changes for you.

## Closing months and years

- Set up your **financial year** (for example January to December).
- **Lock a month** once its figures are final, so nobody can change them by mistake. Months are
  locked in order.
- **Close the year** at the end: Tatook carries the year's profit or loss into your balance sheet.
  A closed year can be reopened if needed.
- **Opening balances:** when you start, enter what each account held on your first day (bank, cash,
  what customers owe, what you owe).

## Dashboards

Each person sees a dashboard for their work, showing only what they are allowed to see:

- **Finance management** — cash available, what customers owe you, what you owe, payments waiting
  for approval, and how you are doing against budget.
- **Accounting** — items waiting to be approved, bank checks still to do, months and tax.
- **Administration** — approval rules and controls.

Dashboards are always up to date.

## Audit trail

The audit trail records every important action — approvals, rejections, payments, corrections,
locked months, changed settings, petty cash — with **who did it, when, and what changed**. Nobody can
edit it.
