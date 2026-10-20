# ADR-0006: Miles Finance & Accounting grows out of finance-app

- Status: Accepted (2026-10-20)
- Phase: Finance F1 onwards (`docs/erp/finance-roadmap.md`)

## Context

`finance-app` (registry application `finance`, `{workspace}/finance`) keeps every organization's
books: chart of accounts, double-entry ledger enforced by PostgreSQL, payables, receivables,
banking, and three statements. Pharmacy and Retail post their sales, bills, returns and stock
adjustments into it as events. The catalog already sells it on its own as the product
`finance_accounting` (pilot, requires `finance`).

Customers who buy only Finance & Accounting need what an accountant expects from a standalone
package: periods and year-end close, bank reconciliation, tax, multicurrency, cost centres,
budgets, fixed assets and a full set of reports. `MASTER-PLAN.md` forbids a second accounting
engine.

Options considered:

1. **Grow `finance-app`**: the advanced modules are added to the same application and database,
   on the same ledger the products already post into.
2. A new `accounting-app` for the advanced modules, reading or copying finance-app's ledger.
3. A new `accounting-app` replacing finance-app, with products moved to post into it.

## Decision

Option 1.

- One ledger: a product's sale and the accountant's reconciliation, close and reports see the
  same journals. Options 2 and 3 mean two books that can disagree, or a migration of every
  product's event stream.
- Period locks, reconciliation and revaluation must hold in the same transaction as a posting;
  across two databases they cannot.
- Already sellable alone (`finance_accounting`) and already event-fed; no platform change.

## Consequences

- Every advanced module lives in `finance-app` under `App\Finance` and `{workspace}/finance`.
  Permissions stay `finance.*` in `finance-app/platform/catalog.json`.
- The ledger stays the only writer of journals (`App\Finance\Ledger`); new modules post through
  it, so the PostgreSQL balance and append-only checks cover them.
- Products keep posting events unchanged. Rules that would refuse an accountant's entry
  (a locked period) never refuse a product's event: it is moved into the first open day, with
  the original date in the memo, so a product never has to retry forever.
- Larger modules that some customers will not pay for (fixed assets, multicurrency) are plan
  features of `finance`, like `payroll` in HR, not separate applications.
- `finance_accounting` moves from `pilot` to `available` when the pilot list in the roadmap is met.
