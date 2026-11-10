# Phase 12: project money in the books

Paying project spending and receiving grant payments now post journals. Before this, the books and the rest of the app shared nothing, and finance staff would have entered everything twice.

## What was built

| What happens                | Journal                                                           |
| --------------------------- | ----------------------------------------------------------------- |
| An expense is paid          | Dr the category's account, Cr the payment account, on the project |
| A paid expense is voided    | The mirror image, dated the day it is voided                      |
| A grant payment is received | Dr the payment account, Cr grant income                           |

- **Accounting → Project posting**: choose the accounts, and post earlier records.
- New starting accounts: 4100 Grant income, 6500 Project materials, 6510 Project equipment, 6520 Project travel, 6530 Project subcontractors. Books already open get them on the next posting.
- A project gets an accounting project (a dimension) under its code the first time it posts, so the report by dimension includes it.
- The journal number links from the expense and the grant payment, for people with `finance.journals.view`.

## Rules

- The journal and the payment are one transaction. **If the books refuse, the payment is refused too**, and the message shows on the form.
- Money in another currency needs an exchange rate on or before the date. Without one, the payment cannot be recorded until someone adds it under Currencies. A rate is taken as it is on the day; there is no gain or loss between commitment and payment.
- A date in a locked month moves to the first open day, the rule for journals that come from other parts of the app.
- These journals belong to their document: they can't be reversed by hand in Journals. Void the expense instead.
- A journal matched to a bank statement line can't be voided until it is unmatched there.
- Approved, unpaid spending isn't in the books, because no money has moved.

## Files

- `app/Accounting/ProjectBooks.php`, `app/Accounting/Models/ProjectPostingAccount.php`
- `database/migrations/2026_11_05_001200_link_projects_to_books.php`
- `app/Actions/Spending.php`, `app/Actions/Grants.php`
- `app/Http/Controllers/Accounting/ProjectPostingController.php`, `routes/web/accounting.php`
- `resources/js/pages/portal/accounting/project-posting/index.tsx`
- `resources/js/pages/portal/projects/expenses.tsx`, `resources/js/pages/portal/funding/grants/show.tsx`
- `tests/Feature/ProjectBooksTest.php`

## Tested

- Automated, on SQLite: each posting, void, grant receipt, conversion, refusal without a rate leaving nothing behind, a locked month, chosen accounts, posting earlier records (partial failure, then safe to repeat), permissions, and another organization's account.
- The whole suite, PHPStan on the changed PHP, and `tsc`.
- Migration up, down and up on SQLite.

## Not tested

- **Real browser:** the Project posting page, the journal links and the new error on the grant page have not been seen. `npm run build` currently fails (see below).
- **MariaDB:** neither the migration nor the postings have run on it.

## Known limits

- **One payment account.** All project money posts to the one chosen account. A payment made in cash or by mobile money goes to the bank account unless someone corrects it with a journal, and bank reconciliation will show the difference.
- **Grant income on receipt.** The default treats a grant as income when the money arrives. Where the accountant defers it, choose a liability account; nothing moves it to income as it is spent.
- **Labour isn't posted.** Time at cost is an internal charge. Salaries reach the books when they are paid, and there is no payroll here.
- **No grant dimension.** Journal lines carry the project, not the grant. Spending by grant still comes from the project pages.
- **Equipment is an expense by default.** Choose an asset account to capitalize it; that doesn't register a fixed asset.

## Build problem found, not caused by this work

`package.json` has an uncommitted change of `vite-plus` from `0.3.0` to `^1.5.0`, and `npm run build` fails with `Expected @voidzero-dev/vite-plus-core@1.5.0, but found vite@8.3.4`.

`php artisan wayfinder:generate` also hangs when the database can't be reached. Add `--skip-database` to run it without one.
