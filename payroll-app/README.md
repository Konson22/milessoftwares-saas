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
                                              Payroll ── finance.payroll.posted ──▶ Finance
                                                      └─ finance.payroll.paid ───▶
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
- **Runs** (`App\Payroll\Runs`, `App\Payroll\Calculator`): one per month. Prepare → recalculate as
  often as needed → approve (never by whoever last prepared it; the run then never changes, and a
  PostgreSQL trigger keeps its payslips that way) → record as paid. Joiners and leavers are paid for
  the days they worked; hourly staff for their timesheet hours, overtime at 1.5×. A run whose
  deductions exceed someone's pay is refused, naming them.
- **Payslips**: a printable page per person.
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

Production: `ops/nginx/products/payroll.conf`, `ops/php-fpm/payroll.conf`, a `payroll` database
and `.env`, then add `payroll` to `PRODUCTS` for `ops/deploy/deploy.sh`.
