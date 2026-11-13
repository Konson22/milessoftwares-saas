# Payroll (`payroll-app`)

Monthly payroll for any business on the platform, served at `{workspace}/payroll`. A shared
module (registry application `payroll`): sold on its own or with any Miles ERP, fed by HR and
products, posting to Finance ([ADR-0008](../docs/adr/0008-payroll-separate-application.md)).
Built from `templates/product-app` and connected through
[`miles/platform-client`](../packages/platform-client/README.md).

## How it fits

```
 HR ─────────── payroll.employee.joined / .left ──┐
 Restaurant, ── payroll.timesheet.submitted ──────┤
 Pharmacy, …    (and .employee.* without HR)      ▼
                                              Payroll ── finance.payroll.posted ───▶ Finance
                                                      ├─ finance.payroll.paid ─────▶ (per batch)
                                                      └─ finance.payroll.reversed ─▶
```

- Events never carry pay. Pay, allowances, deductions and payment accounts are entered here.
- A business without HR adds people here (People → Add person).
- Finance is optional: without it, the outbox skips the journal events.

## What is here

- **People** (`App\Models\Employee`, `App\Payroll\Staff`): numbers `P-00001` (or HR's number),
  branch and department from the platform, monthly or hourly, joining and leaving dates. Someone
  HR keeps in step shows "Kept in step by hr"; only their pay changes here. Branch-scoped members
  see their branch only.
- **Pay** (organization-wide `payroll.pay.*` only): basic pay from a date (append-only), allowances
  and deductions per person (fixed or % of basic, between dates), the bank or mobile money account
  they are paid into (encrypted with `APP_KEY`: **back it up with the database**).
- **Settings**: the organization's allowances and deductions; income tax bands (monthly, progressive)
  and social security (employee, employer, optional cap, optionally taken off taxable pay), from a
  date. The South Sudan preset (`App\Payroll\Presets`) fills the form; check it before saving.
- **Runs** (`App\Payroll\Runs`, `App\Payroll\Calculator`): one live run per month, in the
  currency it was prepared in. Prepare → recalculate or add one-off adjustments (bonus, arrears,
  advance recovered) as often as needed → optionally reviewed by someone else → approved (never by
  whoever last prepared or adjusted it; the run then never changes, and a PostgreSQL trigger keeps
  its payslips that way) → paid in one or more batches (`PR-00001-P1`…, all unpaid payslips or the
  ticked ones), with **payment instructions** in Excel for the bank or cashier. An approved run
  nobody has been paid from can be **reversed** with a reason; the month can then be prepared again.
- **Maths**: whole numbers throughout (`App\Support\Money`). Hours are hundredths, overtime is 3/2
  of the rate, joiners and leavers are paid days worked / days in the month; each figure is
  rounded half up once. A run whose deductions exceed someone's pay is refused, naming them.
- **Payslips**: a printable page and PDF per person, a PDF of the whole run, the register in Excel.
- **Audit trail** (`App\Payroll\AuditTrail`, `payroll.audit.view`): who prepared, reviewed,
  approved, adjusted, paid or reversed each run, and who changed people, pay, payment details
  (the method, never the account) and rules. Append-only (a PostgreSQL trigger refuses changes).
- **Roles** (catalog 1.1.0): clerk, officer, reviewer, approver, administrator, auditor
  (`docs/runbooks/payroll.md`).
- **Events in** (`POST /payroll/events`, `App\Http\Controllers\EventController`): verified like
  Finance's (platform-signed event token, inbox applied once).

## Develop

```bash
createdb payroll                 # owner: your local DB user
composer setup                   # install, .env, key, migrate, build
cd ../central-app && php artisan platform:local-product payroll-app --url=http://127.0.0.1:9200 --entitle=<workspace-slug>
cd ../payroll-app && composer dev   # :9200, queue, Vite
composer ci:check                # format, lint, types, Pint, PHPStan, Pest
```

Open `http://<workspace-slug>.milesoftwares.localhost/payroll` (proxy entry in
`ops/local-dev/apache-dev.conf`). Set `PLATFORM_FINANCE_EVENTS_URL` here, and
`PLATFORM_PAYROLL_EVENTS_URL=http://127.0.0.1:9200/payroll/events` in `hr-app/.env` (and any product
that sends hours), to connect the modules.

Production: `docs/runbooks/payroll.md` (database, `.env`, registration, nginx, php-fpm, systemd,
roles, and what to do when an event to Finance is stuck).
