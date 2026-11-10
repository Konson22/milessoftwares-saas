# ADR-0008: Payroll as its own shared module

- Status: Accepted (2026-11-09)
- Supersedes: the "HR and Payroll are one application" part of
  [ADR-0005](0005-hr-and-payroll-application.md) (option 3 there, revisited here as that ADR asks)
- Code: `payroll-app` (registry application `payroll`, `{workspace}/payroll`)

## Context

ADR-0005 put Payroll inside `hr-app` as a plan feature, because an employee and their pay were
seen as one record set. Since then the product owner has asked for Payroll to be **a module any
business can add**: a restaurant, pharmacy or shop that pays staff but has no HR department, a
business that keeps only Finance & Accounting, and of course one that runs HR. Inside `hr-app`,
Payroll could only be bought by buying HR.

What changed the balance:

- The platform already treats `payroll` as an events audience (`platform-client.modules.payroll`,
  `PLATFORM_PAYROLL_EVENTS_URL`), and `EventTokenIssuer` issues tokens for any `shared_module`.
- `Outbox` and `Inbox` make "a distributed commit for every hire" a non-issue: HR records the
  event in its own transaction and Payroll applies it once.
- H5 (payroll inside HR) was never built, so nothing has to move.

## Decision

Payroll is a separate application, `payroll-app`, kind `shared_module`, own database.

- **Its own register of people it pays** (`employees`): entered in Payroll by a business without
  HR, or kept in step by `payroll.employee.joined` / `payroll.employee.left` from HR or any product
  that keeps staff. A person sent by another application has `source_app` + `source_reference`;
  their work details change there, their pay only in Payroll.
- **Events never carry pay** (`Envelope::SENSITIVE_KEYS` still applies). Pay, allowances,
  deductions and the account each person is paid into are entered in Payroll; payment details are
  encrypted (`encrypted:array`).
- **Hours** come from any product as `payroll.timesheet.submitted` (audience `payroll`, no longer
  `hr`), for people paid by the hour.
- **Statutory rules** (income tax bands, social security rates and cap) are data with an effective
  date per organization. A South Sudan preset (Financial Act 2023/24 bands, kept for 2024/25;
  pension 8% / 17%) can be loaded into the form, marked to be checked before saving.
- **Runs**: one calendar month each; prepared (draft, recalculated freely), approved by someone
  other than the last preparer (refused in code, catalog SoD `warn`), then recorded as paid. An
  approved run's payslips are unchangeable (PostgreSQL trigger).
- **Finance** gets one summarised journal per approved run (`finance.payroll.posted`) and one for
  the payment (`finance.payroll.paid`), totals only. Finance has system accounts for them
  (2210 PAYE, 2300 Salaries payable, 2310 Social security payable, 2320 Other payroll deductions,
  6100 Salaries and wages, 6110 Employer social security); an older chart's keyless 6100 is
  adopted.
- **Selling**: a `payroll` ERP product (Payroll required; HR and Finance optional) and an optional
  application of Education and NGO. Pharmacy, Retail and Restaurant gain it as optional once Payroll
  is deployed (the product sync keeps a released product planned while an application it names is
  not registered).

## Consequences

- `hr-app` is HR only; the `payroll` plan feature of ADR-0005 is not used. Its catalog still names
  it "HR & Payroll": renaming it is a catalog change for the product owner to decide.
- A business with HR and Payroll keeps people in two places: HR is the owner of who works where,
  Payroll of what they are paid. Someone added in Payroll by hand is not sent back to HR.
- Payroll's branch split towards Finance (ADR-0005's minimum-headcount rule) is not built: one
  organization-wide journal per run.
- Not built yet: payslip PDFs (the payslip page prints), salary advances and loans schedules,
  adjustment runs, bank payment files, self-service payslips, more than one run a month.
