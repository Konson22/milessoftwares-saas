# Tatook M&E (`mel-app`)

Monitoring, evaluation and data collection, as its own Tatook application
(ADR-0019). It has:

- application code `mel`;
- its own PostgreSQL database;
- pages served at `{workspace}/mel`.

Sign-in, organizations, subscriptions and trials come from Central, through
`miles/platform-client`.

The domain design is in ADR-0011 (M&E) and ADR-0012 (Data Collection). The
code came from `project-management` (`App\Mel`, `App\Collect`), where it lived
under ADR-0013. It keeps the same namespaces, tables (`mel_*`, `collect_*`) and
route names (`mel.*`).

## Layout

| Path | What |
|---|---|
| `app/Mel` | Programmes, frameworks, indicators, targets, periods, submissions, review, performance, reporting, audit |
| `app/Collect` | Form definitions, versions, the logic and validation engine, assignments, scope |
| `app/Http/Controllers/Mel` | Pages |
| `app/Platform/SetUpWorkspace` | Provisioning: settings and starting dimensions |
| `resources/js/pages/mel`, `resources/js/mel` | Pages, the form builder and renderer, `rules.ts` |
| `platform/catalog.json` | Catalog `mel`: M&E permissions, the nine M&E roles, separation of duties |

The form engine runs the same cases in PHP and TypeScript:
`contracts/collect/rules-cases.json`, at the repository root.

## Tatook Projects

The link to Projects is **off** (`App\Mel\LinkedProjects::enabled()`).
- Units and results keep `linked_project_id` and `linked_milestone_id` as plain
  ids, never foreign keys.
- No new link can be made, and linked sections are hidden.
- ADR-0019 M3 reads Projects through its integration API, for organizations
  entitled to both apps.

M&E needs nothing from Projects.

## Run

```sh
composer install && npm ci
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan wayfinder:generate --with-form
npm run build        # or npm run dev
```

To register the product locally and write its platform keys into `.env`:

```sh
cd ../central-app && php artisan platform:local-product mel-app --entitle=<slug>
```

A local proxy entry for `/mel` is also needed (`ops/local-dev`).

## Checks

```sh
php artisan test              # SQLite in memory
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
npx tsc --noEmit && npm test && npx vp check
```

To run the suite on PostgreSQL, point `DB_CONNECTION=pgsql` at a test
database. The append-only triggers on audit, reviews, verified results and
form versions run on PostgreSQL only.
