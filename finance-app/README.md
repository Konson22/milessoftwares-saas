# Product template ("finance")

The starting point for every Miles Softwares product (Pharmacy, Retail, School, ...): Laravel 13 +
Inertia + React, connected to the platform through
[`miles/platform-client`](../../packages/platform-client/README.md).

```bash
php templates/new-product.php pharmacy "Pharmacy" 8400   # creates pharmacy-app/ and prints the next steps
```

The script renames every `finance` (code, URL path, permissions, cookie, database) and the
display name, and leaves this folder untouched. Change the template here when every future product
should get the change.

## What a product gets

- **Lives at `{workspace}/finance`.** Every route is registered under the path
  (`routes/web.php`); the web server passes it through unchanged. Built assets go to
  `public/finance/build`.
- **Platform sign-in**, no passwords. `users` mirrors platform users (uuid = platform subject).
- **Every page behind `platform`**: the workspace comes from the host, the member's platform
  access is checked on every request, catalog permissions work with `->can(...)` and `@can`.
- **Tenant-owned data** with `BelongsToTenant` (`app/Models/Record.php`), fail closed.
- **Provisioning**: `App\Platform\SetUpWorkspace` prepares each newly entitled organization
  (`workspaces` row with its currency and time zone).
- **The platform's browser policy** (CSP with nonces, frame denial) and its look (Tailwind theme,
  UI kit, fonts), with the workspace header in `resources/js/layouts/product-layout.tsx`.
- **Tests** with `InteractsWithPlatform`: `actingAsMember('acme', ['finance.records.view'])`, no
  network.

`Record` and the records pages are an example: replace them with the product's own features.

## Develop

```bash
composer setup        # install, .env, key, migrate, build
composer dev          # php artisan serve on :8500, queue, Vite
composer ci:check     # format, lint, types, Pint, PHPStan, Pest
```

Catalog (roles and permissions): `platform/catalog.json`. Register with the local platform:
`cd ../../central-app && php artisan platform:local-product templates/product-app --url=http://127.0.0.1:8500`.

## Payroll

Payroll is part of Finance ([ADR-0009](../docs/adr/0009-payroll-in-finance.md)), at
`{workspace}/finance/payroll`: people, pay history, allowances and deductions, statutory rules,
monthly runs and payslips (`app/Payroll`, `app/Http/Controllers/Payroll`, `routes/payroll.php`,
`resources/js/pages/payroll`).

- Permissions `finance.payroll.*`; roles Payroll clerk, officer, approver and manager. Seeing who
  is paid and seeing what they earn are separate; whoever prepared a run cannot approve it.
- Approving a run posts one summarised journal to the ledger, and marking it paid posts the
  payment, each in the same transaction as the run.
- HR (and any product that keeps staff or hours) sends `payroll.employee.joined`, `.left` and
  `payroll.timesheet.submitted` to Finance's `/finance/events`; `App\Payroll\ApplyEvent` applies
  them.
- On PostgreSQL a trigger keeps an approved run's payslips unchangeable.
