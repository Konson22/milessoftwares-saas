# M&E (`mel-app`)

Tatook Monitoring, Evaluation & Learning, served at `{workspace}/mel`. A standalone application
(registry application `mel`) with its own database: sold on its own or with the NGO ERP, linking
to Project Management projects without copying them
([ADR-0011](../docs/adr/0011-monitoring-evaluation-learning.md)). Built from
`templates/product-app` and connected through
[`miles/platform-client`](../packages/platform-client/README.md).

## What is here

- **Programmes** (`App\Mel\Programmes`): donors, dates, reporting units (sites, partners,
  locations), each optionally linked to a Projects project. Access per person through programme
  grants (units × one period window); roles with "every programme" need none.
- **Results frameworks** (`App\Mel\Frameworks`): goal → outcomes → outputs, any depth.
- **Indicators** (`App\Mel\Indicators`, `App\Mel\Measures`): count, number, percentage, rate,
  ratio, amount or yes/no, with definition, unit, direction, frequency, how units combine (add,
  latest, highest, lowest, recompute from the parts, or not at all), breakdowns (age band, sex, …) with their rule, baselines and targets with a reason
  for every change. Editing a definition with results under it creates a new version.
- **Results** (`App\Mel\Submissions`, `App\Mel\Import`, `App\Mel\Evidence`): one open result per
  indicator, unit and period; entered by hand or imported from CSV/XLSX in two steps (check, then
  apply; a sheet with any error applies nothing). Evidence files (allow-listed types, 20 MB) with a
  "share with donors" flag. Aggregate counts only, never personal data.
- **Review** (`App\Mel\Review`): submitted → verified or rejected with a reason → published.
  Separation of duties from Settings. A verified figure is never changed: a correction replaces it
  only once verified itself (PostgreSQL triggers on `verified_results` and `review_decisions`).
- **Reports** (`App\Mel\Reporting`, `App\Mel\Performance`): IPTT, framework, target vs actual,
  completeness and verification, programme summary; on screen and in Excel. Only verified data
  (published only, for donors). No universal sums, averages or programme-wide percentages.
- **Audit trail** (`App\Mel\AuditTrail`): append-only (a PostgreSQL trigger refuses changes).
- **Roles** (catalog 1.0.0): administrator, manager, officer, field officer, project manager,
  implementing partner, donor viewer, independent evaluator (`docs/runbooks/mel.md`).
- **Maths**: decimal strings throughout (`App\Mel\Numbers`, bcmath, 6 places); percentages are
  recomputed from their numerators and denominators.

## Develop

```bash
createdb mel                     # owner: your local DB user
composer setup                   # install, .env, key, migrate, build
cd ../central-app && php artisan platform:local-product mel-app --url=http://127.0.0.1:9400 --entitle=<workspace-slug>
cd ../mel-app && composer dev    # :9400, queue, Vite
composer ci:check                # format, lint, types, Pint, PHPStan, Pest
```

Open `http://<workspace-slug>.milesoftwares.localhost/mel` (proxy entry in
`ops/local-dev/apache-dev.conf`). Tests run on in-memory SQLite locally and on PostgreSQL in CI,
where the triggers are exercised.

Production: `docs/runbooks/mel.md` (database, `.env`, registration, nginx, php-fpm, systemd,
roles, and the checks for the database protections).
