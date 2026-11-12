# ADR-0010: Payroll is its own application again

- Status: Accepted (2026-11-12)
- Supersedes: [ADR-0009](0009-payroll-in-finance.md) (Payroll as part of Finance & Accounting)
- Code: `payroll-app`, at `{workspace}/payroll`

## Context

ADR-0009 moved Payroll into Finance, so a business could only run payroll by adding Finance.
The product owner now wants Tatook Payroll as a product of its own: a business must be able to
pay its staff with Payroll alone, and add HR or Finance later. The ownership lines are:

- **HR**, where installed, owns the employee lifecycle (hire, transfer, leave).
- **Payroll** owns pay: salary history, allowances and deductions, statutory rules, runs,
  payslips and their approval.
- **Finance** owns the books and paying money out: journals, payment requests and execution.

There is one payroll engine, one register of who is paid, and one ledger. Nothing is kept twice.

## Decision

- `payroll-app` comes back (it was deleted in ADR-0009 and restored from `5490d5a`) and takes in
  everything Finance's payroll gained in between: payslip PDFs (one person, a whole run), the run
  register as Excel, and row locks so an approval or payment cannot be sent twice.
- Finance's payroll is removed: its controllers, pages, models, routes and tests. Its tables are
  dropped by `2026_11_13_100000_drop_payroll_tables`, which refuses to run while any of them has
  rows. The 2026-11-12 migration that created them stays, as it has run in production.
- Finance's catalog 1.13.0 has no `finance.payroll.*` permissions, no Payroll roles and no payroll
  separation-of-duties rule. Any holders of the four retired roles are revoked with
  `platform:catalog-retire-role finance <role>` before the catalog is reassigned.
- Payroll's own catalog carries `payroll.*` permissions and its roles. Whoever prepared a run
  still cannot approve it.
- Payroll talks to Finance only through the outbox (audience `finance`):
  `finance.payroll.posted` (run totals only, never one person's pay) when a run is approved, and
  `finance.payroll.paid` when it is recorded paid. Finance posts them with `App\Finance\PostEvent`.
  Without Finance, the outbox skips them, and Payroll works on its own.
- HR sends `payroll.employee.joined` and `payroll.employee.left` to Payroll again (audience
  `payroll`, `PLATFORM_PAYROLL_EVENTS_URL`, `platform-client.modules.payroll`). Finance accepts
  only `finance.*` events.
- Money is integer minor units. A run is in one currency; statutory rules are configured by the
  organization, with presets that are starting points and make no claim of compliance.

## Consequences

- One more application to deploy and monitor (database, PHP pool, queue and scheduler units,
  nginx location), on the same server.
- The platform offers Payroll as a product again: the retired `payroll` ERP product is restored
  in central-app, with its marketing page and icon.
- Approval and booking are no longer one transaction: an approved run reaches the books when
  Finance accepts the event, retried by the outbox until it does.
- Later phases (multi-currency runs, adjustments and reversals, payment-instruction exports, a
  Finance payment request per run, reports) build on this split without moving pay into Finance.
