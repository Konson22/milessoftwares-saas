# ADR-0005: HR and Payroll as one separate application

- Status: Accepted (2026-10-19)
- Phase: HR H0 onwards (`docs/erp/hr-roadmap.md`)

## Context

Every ERP product needs staff records, and Education and NGO need payroll. HR must be sellable to
any business, including one that only uses Finance & Accounting. HR and Payroll hold the most
sensitive data on the platform: pay, bank accounts, identity numbers, dates of birth.
`docs/architecture.md` planned HR and Payroll as two separately deployed modules; ADR-0003 put
Inventory, Procurement and Sales inside the ERP workspace application because stock and the
document that moves it must commit together.

Options considered:

1. **A separate `hr-app`** (from `templates/product-app`), one registry application `hr`, with
   Payroll as a plan feature (`payroll`, optionally limited by headcount).
2. An `Hr` module inside the ERP workspace application (`pharmacy-app`).
3. Two separate applications, `hr` and `payroll`.

## Decision

Option 1.

- HR has no transaction that must commit with stock, so ADR-0003's reason does not apply.
- Its own database and credentials: a fault in a stock or sales feature cannot reach pay, bank
  or identity data.
- Sellable on its own or with any product, without running the workspace application.
- One application, because an employee and their pay are one record set: two deployments would
  mean a distributed commit for every hire and every run, and serving two registry applications
  from one deployment is not supported by platform-client yet.

## Consequences

- Branches, departments and positions stay owned by central-app `Structure`. HR stores their ids
  and reads them through `GET /api/v1/organizations/{id}/departments` and `.../positions` (new),
  like branches. The platform offers no member directory: an employee is linked to a platform
  membership (self-service, H7) when that person signs in with the matching verified email.
- Personal details are stored encrypted (Laravel `encrypted:array`, application key), behind
  organization-scoped permissions (`hr.personal.view`, sensitivity 4); every view and change is
  logged by field name, never value.
- Payroll reaches Finance as **one summarised journal per approved run** (no per-employee pay
  leaves HR, as the shared-module kit already enforces). A split by branch or department is
  allowed only above a minimum headcount, so a small department's line never reveals one
  person's pay.
- Events about staff from other products (`hr.employee.joined`, `payroll.timesheet.submitted`)
  are addressed to audience `hr`, not `payroll`.
- `erp-products.json` names `hr` alone (no `payroll` application). Payroll is turned on by the
  `payroll` plan feature (`EffectiveAccess::feature('payroll')`).
- Statutory rules (income tax bands, social security rates and caps) are data with effective
  dates per organization, never code; the first preset (South Sudan) is confirmed by someone
  local before payroll goes to pilot. Payroll currency equals the books currency until Finance
  supports foreign exchange (roadmap Phase 4).
- Staff documents go to a dedicated private filesystem disk (`hr_documents`): local and private
  for the pilot, S3-compatible (DigitalOcean Spaces) by configuration later, short-lived signed
  downloads only.
- Option 2 and option 3 are not to be revisited without a new ADR.
