# ADR-0011: Tatook M&E (Monitoring, Evaluation & Learning) is its own application

- Status: **Superseded in part by ADR-0013** (2026-11-15): M&E is a module of Tatook Projects
  (`project-management`), not its own application, and `mel-app` is retired. The domain design here
  (schemas, calculation rules, workflow) still holds. Paths and codes below are historical.
- Code: `mel-app`, registry application `mel`, served at `{workspace}/mel`, dev port 9400

## 1. Findings from the repository

**Platform shape.** Every product is a Laravel 13 + Inertia/React app with its own PostgreSQL
database. All products sign in through central OIDC (`miles/platform-client`) and are served under a
path on the workspace host. Products use catalog permissions (versioned JSON, scoped roles, SoD
pairs) and the effective-access API, which supports plan features. Provisioning goes through
`App\Platform\SetUpWorkspace`, and tenant isolation through `BelongsToTenant`, which fails closed.
`templates/new-product.php` creates a new product from the blueprint. Payroll (ADR-0010) is the most
recent example of a product that stands alone and integrates optionally.

**Cross-app integration** happens only through business events: the outbox in the sender, and a
platform-signed event token plus an idempotent inbox in the receiver (`docs/shared-modules.md`).
An event token is issued only while the organization is entitled to both apps. Otherwise the outbox
marks the event `skipped`. So "integrate when both are subscribed" already exists as a mechanism.
No product reads another product's database or calls another product's read API.

**Project Management (`project-management`, app `projects`).** It owns programmes (a group of
projects with no money of its own), projects, milestones, deliverables, tasks, budgets, spending,
grants, funders and a donor portal. Donor contacts are confined members of the NGO's workspace who
see only `DonorProjection` fields. Its roadmap lists **MEAL as "Missing / not started"**, and its
donor portal report says *"Indicators and results are not shown: they wait for MEAL"*.
`ProjectMetrics` computes progress and health. PM already sends `finance.*` events through
`App\Support\FinanceEvents`.

**No M&E code exists anywhere.** There are no indicators, results frameworks, reporting periods or
submissions. The other matches for "indicator" are UI spinners and fuel gauges.

**Reusable pieces:**

| Need | Existing piece |
|---|---|
| Sign-in, tenancy, access, provisioning, webhooks | `miles/platform-client` via `templates/product-app` |
| Events in from PM | `shared-module-kit` inbox, as in Payroll's `EventController` |
| Charts | PM's `resources/js/components/charts.tsx` (plain HTML, `--viz-*` tokens, table fallback). Copied, so no chart dependency is added |
| UI kit | `@miles/ui` (`packages/ui`) |
| Excel export | `App\Support\XlsxExport` (OpenSpout), already in Finance and Payroll |
| Spreadsheet import | none exists. Use the OpenSpout **reader** (same package), so no new dependency |
| PDF | `barryvdh/laravel-dompdf`, as in Payroll and PM |
| Append-only audit | Payroll's `AuditTrail` + PostgreSQL trigger pattern |
| Locked-after-approval rows | Payroll's `payslips_locked` trigger pattern |
| Private files | PM's private-disk downloads, authorized only through the app |
| Money | `App\Support\Money` (integer minor units) |

## 2. Decision: application architecture

A new product, **`mel-app`**, generated from `templates/product-app` like Payroll:

- It has its own navigation, dashboard, catalog (`mel.*` permissions and roles), plans and
  entitlement (`mel` in `erp-products.json`: a standalone product, and **optional** in the NGO ERP),
  reports, API, settings and **database**.
- **Why its own database, given the brief's warning against one:** every product on this platform
  has its own database, and the architecture forbids products from reading each other's tables.
  Following the platform convention is the "no new architecture" choice. Putting M&E inside
  `project-management` would make M&E impossible to sell without PM's deployment, and would blur
  the boundary the brief asks for. This choice adds no microservice and no second identity system:
  M&E is one more Laravel app on the same server, with its own php-fpm pool, queue and scheduler
  units, the same as Payroll.
- It works **without Projects**: programmes and reporting units are M&E's own records.

**Ownership:**

| Owned by | Records |
|---|---|
| **Projects** (authoritative) | projects, milestones, tasks, budgets, spending, grants, donor contacts |
| **M&E** | programmes (M&E sense), reporting units, results frameworks, indicators and versions, baselines, targets, periods, submissions, verified results, evidence, reviews, M&E access grants |
| **Finance** | the books. M&E never posts to the ledger and has no ledger |

## 3. Proposed database schema (`mel` database, PostgreSQL)

Conventions are those of `docs/architecture.md`: UUIDv7 keys, `timestamptz`, a non-null
`organization_id` on every tenant table with `BelongsToTenant`, composite FKs
`(organization_id, id)` to tenant parents, `CHECK` constraints on statuses, and no hard deletes of
anything that has been submitted. Indicator values are `numeric(20,6)` and calculations use bcmath,
never floats. Money uses integer minor units.

**Organization level**

- `workspaces` (template), extended with M&E settings: `verifier_must_differ_from_submitter`
  (default **true**), `publisher_must_differ_from_verifier` (default false), and the performance
  bands (default 90 / 70 %).
- `locations`: a hierarchy (country, state, county…), with `parent_id` and `code`.
- `partners`: implementing organizations (name, type).
- `dimensions` + `dimension_categories`: Sex, Age group, Location, Partner, Population group, and
  dimensions the organization defines. Age groups are bands, never dates of birth.
- `audit_events`: append-only, with a pgsql trigger refusing UPDATE and DELETE.

**Programme level**

- `programmes`: code (unique per organization), name, objective, description, start/end, status
  (`draft`, `active`, `closed`), `archived_at`, currency, a funding summary (declared, not
  accounting), and the manager. Many-to-many with `locations`.
- `programme_units`: the reporting units (project, site, partner, other), with an optional
  `partner_id` and an optional **`linked_project_id`**. Unique on
  `(programme_id, linked_project_id)`, so one PM project is never linked twice.
- `reporting_periods`: label, frequency (monthly, quarterly, semi-annual, annual, custom),
  starts/ends/due dates, and status (`open`, `closed`). Generated from a schedule. Periods of the same
  frequency cannot overlap: a pgsql exclusion constraint, plus an app check.
- `programme_access`: grants for people without organization-wide access (field officers,
  partners, donors, evaluators). Each grant has a programme, user, an optional unit, an optional
  period window, `granted_by`, `expires_at` and `revoked_at`.

**Results framework**

- `result_levels`: per programme, an ordered set of labels (default Goal → Outcome → Output →
  Activity). Organizations rename, add or drop levels, so no donor template is forced.
- `results`: level, `parent_id` (must be exactly one level up), code (`1.2`), statement,
  description, assumptions, risks, means of verification, responsible person, an optional
  `linked_milestone_id`, sort order and `archived_at`. Results have documents (evidence table).

**Indicators**

- `indicators`: a stable identity with programme, result, code (unique per programme), status
  (`draft`, `active`, `retired`), responsible person, reporting frequency and
  `current_version_id`.
- `indicator_versions`: the definition. Fields: name, definition, type (input, process, output,
  outcome, impact); **measure** (count, percentage, rate, ratio, average, currency, yes/no); unit
  label, decimals, numerator and denominator labels, and rate multiplier; calculation method;
  direction (increase, decrease, maintain within range); **aggregation over time** (sum, latest,
  max, recompute from numerator/denominator, none); **aggregation over units** (sum, recompute,
  none); data source, collection method, means of verification, cumulative flag; `effective_from`
  period, change reason and author. A version **cannot change once a submission uses it**
  (trigger). A revision is a new version, so old reports keep the definition they were made under.
- `indicator_breakdowns`: the disaggregation for each version, by one dimension or a pair of
  dimensions (Sex × Age), with a reconciliation rule (`must_equal_total`, `at_most_total`, `none`).
  Nothing is assumed to sum: the rule decides.
- `indicator_baselines` and `indicator_targets`, by indicator × (period) × optional unit. They store
  the value, or the numerator and denominator, and for "maintain" a target min and max. They have a
  status (`draft`, `approved`). An approved row is never edited: a change is a new row with
  `supersedes_id`, a reason and its author. A partial unique index allows one live row per key.

**Data and verification**

- `submissions`: indicator, **indicator_version_id**, period and unit. Fields: value or numerator
  and denominator, narrative, deviation explanation, status (`draft`, `submitted`, `under_review`,
  `verified`, `published`, `rejected`, `superseded`), revision number and `supersedes_id`; source
  (`manual`, `import`, later `kobo` and `odk`), `source_reference` and `import_batch_id`; who
  submitted and when. Constraints: one live submission per (indicator, period, unit) as a partial
  unique index (duplicate detection), and a unique `(organization_id, source, source_reference)`
  so re-importing a file changes nothing.
- `submission_values`: the disaggregated cells (breakdown, category a, category b, value or
  numerator and denominator).
- `review_decisions`: append-only. Holds submitted, review started, verified, rejected (a reason is
  required), published and correction-opened, with user, comment and time.
- `verified_results`: a frozen copy written when a submission is verified, holding the values, the
  version and the verifier. `published_at` and `published_by` are set on publication, and
  `superseded_at` when a correction is verified. A trigger lets only those three columns change.
  **Every report reads this table**, never draft submissions.
- `evidence_files`: private disk, sha256, size, MIME type and uploader. They attach to a submission,
  a result or a programme, and carry a `share_with_donors` flag. They are locked once their
  submission is submitted.
- `import_batches`: file name and sha256, period, status (`validated`, `applied`, `failed`), row
  counts and errors per row. Import is two steps: validate (a preview with errors), then apply in
  one transaction.

**References to Projects** (read-only, written only by events)

- `linked_projects`: external id, code, name, status, dates, progress %, approved budget and spent
  (minor units, currency), and `synced_at`. Unique on `(organization_id, external_id)`.
- `linked_milestones`: external id, name, due date and reached date.
- `inbox` (shared-module-kit schema).

**Phase 2, designed now but not created:** `evaluations` (baseline, midterm, final), each with
questions, methodology, findings, recommendations, management responses and follow-up actions.
These hang off a programme and optionally a result or indicator, and never off `submissions`.

## 4. Calculation rules (no universal sum or average)

- Percentages, rates and ratios are stored as **numerator and denominator**. An aggregate is
  recomputed as Σnumerator / Σdenominator, never as an average of percentages. If a unit is
  missing its denominator, the aggregate is shown as incomplete and not computed.
- Over time and across units, each indicator follows its own declared rule. `none` means no total
  is shown.
- Disaggregated cells are reconciled with the total only by the breakdown's rule.
- Performance is **per indicator, per period**: actual vs target, % of target, and progress from
  baseline, with the direction taken into account. A status band (on track, needs attention, off
  track) uses the workspace's declared thresholds, and "no target" or "no data" is shown as such.
- **No overall programme percentage.** "Programmes needing attention" is a count of off-track
  indicators and overdue or missing submissions.

## 5. Workflow and permissions

`draft → submitted → under_review → verified → published`. A submission can be rejected from
`submitted` or `under_review` back to the submitter, who corrects and resubmits. A verified or
published value is never edited. A correction is a new revision. While the revision is in progress,
the old verified result stays in reports. When the revision is verified, the old one is superseded.
Every step writes a `review_decisions` row and an audit event inside the same transaction, with
row locks so a decision is never applied twice (as Payroll does).

**Catalog `mel` 1.0.0.** Checks are by permission, never by role name. They run in route
middleware (`->can()`) and again in the action classes, and the UI only reflects them.

| Permission | Meaning |
|---|---|
| `mel.programmes.view` | see the programmes one has a grant for |
| `mel.programmes.all` | see every programme without a grant (organization-wide staff) |
| `mel.programmes.manage` | programmes, units, periods, access grants, archive |
| `mel.projects.link` | link a unit to a Projects project |
| `mel.frameworks.manage` | results, indicators, versions, baselines, targets |
| `mel.data.enter` / `mel.data.import` | draft and submit values and evidence; import spreadsheets |
| `mel.data.review` | start review, verify, reject |
| `mel.results.publish` | publish verified results |
| `mel.data.view` | see drafts and submissions (internal) |
| `mel.results.view_verified` | see verified and published results with evidence (evaluators) |
| `mel.results.view_published` | see published results and donor-shareable evidence only |
| `mel.reports.view` / `mel.reports.export` | reports (at the visibility level the person holds) and exports |
| `mel.settings.manage`, `mel.audit.view` | settings, dimensions, locations; the audit trail |

Roles (bundles of the permissions above): Administrator, M&E manager, M&E officer, Field officer,
Project manager, Implementing partner, Donor viewer, Independent evaluator.

- **SoD pairs:** `data.enter` with `data.review` (warn), and `data.review` with `results.publish`
  (warn). At transaction level, a verifier is never the submitter (on by default and configurable),
  and optionally a publisher is never the verifier.
- **Programme scoping:** without `mel.programmes.all`, every query goes through
  `programme_access`. That includes unit restrictions for partners and field officers, and the
  period window for donors and evaluators. Donor viewers reach `verified_results` only where
  `published_at` is set, and only evidence flagged as shareable.

## 6. Integration boundaries

- **Projects → M&E (MVP).** PM's outbox sends `mel.project.updated` v1 (audience `mel`). The
  payload is a snapshot: id, code, name, status, dates, progress %, milestones, and the approved
  budget and spent totals in minor units. It contains no tasks, people, suppliers or line items.
  PM sends it on create, update and milestone change. A command and a daily job
  (`projects:publish-to-mel`) resend changed snapshots, so projects that existed before an
  organization added M&E arrive too. M&E applies it once through its inbox (`POST /mel/events`).
  Without both entitlements, the outbox skips the event and each app works alone. A contract
  schema goes into `contracts/events/`.
- **Finance (deferred).** The PM snapshot already carries approved budget against spent for
  financial context. Expenditure straight from Finance's ledger waits until Finance has a read
  contract. M&E has no ledger.
- **Donor Portal (Phase 2).** M&E sends `projects.results.published` so PM's portal can show
  published indicator results per grant, through `DonorProjection`. In the MVP, donors use M&E's
  own Donor viewer role, scoped by `programme_access`. Donors never gain anything from the
  platform role alone.
- **KoboToolbox / ODK (Phase 2).** A `DataSourceAdapter` interface turns form submissions into
  the same normalized rows that the spreadsheet import validates and applies (indicator code, unit,
  period, values and a `source_reference` such as the Kobo submission uuid). Nothing in the MVP
  calls them.

## 7. MVP scope and what is deferred

**MVP (acceptance 1–11 of the brief):** the app, its catalog, plans, provisioning and
navigation (Overview, Programmes, Results frameworks, Indicators, Data collection, Verification,
Reports, Settings); programmes, units, periods and access grants; the results framework and logframe
matrix; indicators with versions, breakdowns, baselines and targets; the indicator reference sheet;
manual entry and spreadsheet import with validation, duplicate detection and idempotency; evidence;
the full verification workflow; the dashboard with filters (programme, unit, period, location,
indicator, partner); the six reports, printable, with Excel (and PDF for the IPTT); the audit
trail; and the link to PM projects.

**Deferred:** evaluations and learning (§3, Phase 2), results in PM's donor portal, Kobo and ODK
adapters, Finance ledger figures, custom report templates, survey designer, offline collection,
beneficiary registers, complaints, GIS maps, AI, a mobile app, and multi-programme roll-ups of
indicators across programmes.

## 8. Implementation sequence (each step committed locally, nothing deployed)

1. **Foundation:** generate `mel-app` (`php templates/new-product.php mel "Tatook M&E" 9400`);
   catalog 1.0.0; workspace settings; audit trail; navigation shell; dev proxy entry; CI job;
   register with the local platform. In central: the `mel` product in `erp-products.json`
   (standalone, and optional in NGO ERP), the local plan seeder, and the marketing catalog entry.
2. **Core:** locations, partners, dimensions; programmes, units, periods, access grants;
   result levels and results; indicators, versions, breakdowns; baselines and targets.
3. **Data:** submissions with breakdown cells, manual entry, evidence, spreadsheet template and
   two-step import.
4. **Verification:** the workflow, `verified_results`, triggers, SoD settings and the review queue.
5. **Dashboard and reports:** the calculation service (§4), the dashboard, the six reports, print
   views and exports.
6. **Integration:** PM's outbox event, command and daily job; M&E's events endpoint and inbox;
   linking a unit to a project; project progress shown in M&E.
7. **Verification:** the full test suite, including cross-tenant, donor-scope, workflow, SoD,
   import idempotency and calculation tests; migrations on local PostgreSQL 17; `composer
   ci:check`; `npm run build`; PM's suite for the PM change; a browser walk-through if one is
   possible, otherwise reported as not done. Then a runbook (`docs/runbooks/mel.md`), README and
   ops files (nginx, php-fpm, systemd), the same as Payroll.

## 9. Risks

- **Tests run on SQLite, production on PostgreSQL.** Triggers and exclusion constraints are
  pgsql-only (Payroll's are still unverified on pgsql). Plan: run the suite against local
  PostgreSQL 17 as well, which needs DB credentials in `mel-app/.env`.
- **The PM change touches a live app.** The new event is additive and is skipped for
  organizations without M&E, but every skip costs one platform token request. The daily resend
  only sends snapshots that changed.
- **Same origin:** all products share `{slug}.` (architecture §5). M&E uses its own cookie name and
  the template's strict CSP.
- **Personal data:** only aggregate counts are kept. Dimensions marked sensitive (such as
  population group) are hidden from donor views unless shared.
- **Snapshots go stale:** project figures in M&E are as of `synced_at`, which is shown in the UI.
  PM stays the source.
- **Working tree:** branch `products/school-app` holds unrelated uncommitted changes. M&E commits
  stage only their own paths.
- **Deployment:** one more app (database, pool, units, nginx location), as in Payroll's runbook.
  Not deployed until asked.
