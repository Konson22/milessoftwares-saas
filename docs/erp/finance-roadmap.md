# Finance & Accounting roadmap

`finance-app`, served at `{workspace}/finance`, one registry application `finance`, sold alone as
the product `finance_accounting` ([ADR-0006](../adr/0006-finance-accounting-standalone.md)) and
fed by every ERP product's events. Every phase: tests, Pint, PHPStan, `vp check`, `tsc` and build
green; migrations rolled back and forward on PostgreSQL; results in `implementation-status.md`.

Already in place before F1: chart of accounts with system accounts, double-entry ledger
(balanced and append-only in PostgreSQL, reversal-based correction), payables (bills, supplier
payments, credits), receivables (invoices, receipts, voids, returns), bank and cash (money in,
money out, transfers), trial balance, profit and loss, balance sheet, product events.

| Phase | Scope | Acceptance |
| --- | --- | --- |
| F1 Periods and close ✅ | fiscal years split into months; months locked in order ("books locked up to"); year-end close into retained earnings and reopen; opening balances; product events dated in a locked month posted on the first open day | nothing manual posts into a locked month; a product event never fails because of a lock; P&L of a closed year unchanged by its closing journal; balance sheet balances before and after a close |
| F2 Reports ✅ | general ledger detail, aged receivables and payables, cash flow statement (indirect), comparative P&L and balance sheet (previous period, previous year), CSV export, print | aged totals equal the receivable and payable accounts; cash flow ends at the bank and cash balance |
| F3 Bank reconciliation ✅ | statement import (CSV, per-bank column mapping), statement lines, match to ledger movements (suggested by amount and date), create money in/out from a line, reconcile to a statement balance, reconciliation report, matching rules | a reconciled line cannot be matched twice; reconciled balance equals statement balance; a reconciled journal cannot be reversed without unreconciling |
| F4 Tax ✅ | tax codes (rate, sales/purchases, recoverable), tax per line on invoices and bills raised in Finance, tax return report for a period (output, input, adjustments, net), filing, settlement journal | report totals equal the tax account for the period; a filed return's period is locked for tax |
| F5 Dimensions ✅ | cost centres and projects on journal lines (branch, already on journals, later); required-dimension rules per account; P&L and trial balance by dimension | lines without a required dimension refused; dimension reports add up to the unfiltered report |
| F6 Multicurrency ✅ | currencies and daily rates, foreign-currency bank accounts, invoices, bills and payments in a foreign currency, realised gains and losses, period-end revaluation (reversed next day); plan feature `finance.multicurrency` | ledger always balances in the books' currency; revaluation reverses exactly |
| F7 Budgets ✅ | budgets per year, per account per month (optionally per cost centre), copy from last year's actuals, budget vs actual report | budget vs actual's actual column equals the P&L |
| F8 Fixed assets ✅ | asset categories (accounts, method, life), register, acquisition from a bill or journal, depreciation (straight line, declining balance) run monthly into the ledger, disposal with gain or loss; plan feature `finance.fixed_assets` | a run never depreciates below residual value or twice for a month; register net book value equals the asset accounts |
| F9 Review fixes, recurring journals, ratios ✅ | four review fixes (tax on replaced opening balances, case-insensitive search, reversed journals in reconciliation, overdue on the due day); recurring journals (weekly to yearly, month ends kept, reversing accruals, daily `finance:recurring`); financial ratios with the previous period | each occurrence posts once and in order; a locked period stops a template without failing others; ratios computed from the same balances as the statements |
| F10 Journal approvals ✅ | maker-checker for manual and recurring journals, on per organization for all or from an amount; drafts outside the ledger; approve, reject, withdraw; catalog 1.8.0 `finance.journals.approve` | nobody approves their own entry; a draft moves no balance until approved; an unapproved recurring journal never posts |
| Later | multi-level approval routing, consolidation across organizations, bank feeds, e-invoicing, customer statements | — |

## Permissions

| Permission | Sensitivity | Added |
| --- | --- | --- |
| `finance.periods.manage`: lock and unlock months, close and reopen years, opening balances | 3 | F1 (catalog 1.1.0) |
| `finance.banking.reconcile`: import statements, match, reconcile, bank rules | 3 | F3 (catalog 1.2.0) |
| `finance.tax.manage`: tax codes, file, settle and unfile tax returns | 3 | F4 (catalog 1.3.0) |
| `finance.journals.approve`: approve or reject other people's manual and recurring journals, set when approval is needed | 3 | F10 (catalog 1.8.0) |

Viewing the periods page needs `finance.journals.view`. The Accountant role gets every new
permission; Bookkeeper and Auditor do not get `finance.periods.manage`. Bookkeeper gets
`finance.banking.reconcile`; viewing statements and rules needs `finance.banking.view`. Only Accountant gets
`finance.tax.manage`; viewing tax codes and returns needs `finance.reports.view`.

## Pilot list (`pilot` → `available`)

F1–F4 done; one real organization keeping its books only in Finance for a full month, closed and
reconciled; the month's reports checked by its accountant; backups restored once including the
finance database.
