# HR & Payroll (`hr-app`)

Staff records and, from HR Phase H5, payroll for every Miles ERP product, served at
`{workspace}/hr`. One registry application, `hr`; Payroll is the `payroll` plan feature
([ADR-0005](../docs/adr/0005-hr-and-payroll-application.md), phases in
[`docs/erp/hr-roadmap.md`](../docs/erp/hr-roadmap.md)). Built from `templates/product-app` and
connected through [`miles/platform-client`](../packages/platform-client/README.md).

## What is here (H1)

- **Staff** (`app/Modules/Employees`): profiles with work details, employee numbers (`E-00001`),
  managers, an append-only employment history (hired, transfer, promotion, change, left),
  contracts (a new one ends the previous), leaving.
- **Personal details**: identity numbers, date of birth, home contacts, next of kin, bank
  account, stored encrypted with the application key (`encrypted:array`), on their own page,
  organization-wide permissions only. Every view and change goes to `hr_audit_log` by field name,
  never value. **Losing `APP_KEY` loses these details**: back it up with the database.
- **Who sees whom** (`App\Support\StaffScope`): an organization role reaches everyone, a branch
  role that branch's staff, a department role that department's. Anyone out of reach answers 404.
- **Structure** (`App\Support\Structure`): branches, departments and positions come from the
  platform; HR stores only their ids.

## Develop

```bash
createdb hr                      # owner: your local DB user
composer setup                   # install, .env, key, migrate, build
cd ../central-app && php artisan platform:local-product hr-app --url=http://127.0.0.1:8600 --entitle=<workspace-slug>
cd ../hr-app && composer dev     # :8600, queue, Vite
composer ci:check                # format, lint, types, Pint, PHPStan, Pest
```

Open `http://<workspace-slug>.milessoftwares.local/hr` (proxy entry in
`ops/local-dev/apache-dev.conf`). Production: `ops/nginx/products/hr.conf`,
`ops/php-fpm/hr.conf`, and `PRODUCTS="pharmacy finance hr"` for `ops/deploy/deploy.sh` once the
server has the `hr` database and `.env`.
