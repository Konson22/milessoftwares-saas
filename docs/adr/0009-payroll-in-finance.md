# ADR-0009: Payroll is part of Finance & Accounting

- Status: Accepted (2026-11-12)
- Supersedes: [ADR-0008](0008-payroll-separate-application.md) (Payroll as its own shared module)
- Code: `finance-app` (`app/Payroll`, `app/Http/Controllers/Payroll`, `routes/payroll.php`,
  pages under `resources/js/pages/payroll`), at `{workspace}/finance/payroll`

## Context

ADR-0008 split Payroll out of HR into `payroll-app`, so that a business without HR could still
pay its staff. It was built (2026-11-09) but never registered on the platform or deployed.

The product owner has since asked for Payroll to be part of Finance & Accounting. Every business
that runs a payroll needs the books it posts to, and a separate application meant a second
database, a second deployment and an event round trip just to land one journal a month in Finance.

## Decision

- Payroll moves into `finance-app`. `payroll-app` is deleted (it remains in git history, commit
  `5490d5a`). Nothing to migrate: no organization ever had it.
- Its tables (employees, pay, components, statutory rules, timesheets, runs, payslips) are
  Finance tables (`2026_11_12_200000_create_payroll_tables`). Document numbers and the
  shared-module inbox are Finance's own.
- Approving a run and marking it paid post to the ledger **in the same transaction** (the
  `finance.payroll.posted` / `finance.payroll.paid` postings, applied in process) instead of
  going through the outbox. A run is never approved without its journal.
- Permissions become `finance.payroll.*` in Finance's catalog (1.12.0), with the roles Payroll
  clerk, Payroll officer, Payroll approver and Payroll manager. Seeing who is on the payroll and
  seeing what they earn stay separate permissions; preparing and approving a run stay separated.
- Events keep their names. HR (and any product that keeps staff or timesheets) sends
  `payroll.employee.joined`, `payroll.employee.left` and `payroll.timesheet.submitted` to
  **Finance's** events endpoint (`PLATFORM_FINANCE_EVENTS_URL`, audience `finance`). Finance
  accepts the `payroll` module alongside its own.
- The platform no longer offers a Payroll product: the `payroll` ERP product is retired, ERP
  bundles drop it as an optional application, and the public site describes payroll on the
  Finance & Accounting page.

## Consequences

- An organization gets payroll by adding Finance & Accounting; there is no Payroll-only purchase.
  A business without Finance cannot run payroll.
- One fewer application to deploy, monitor and keep on the platform contract.
- `platform-client.modules.payroll` and `PLATFORM_PAYROLL_EVENTS_URL` are gone.
