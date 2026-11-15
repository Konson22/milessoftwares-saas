# ADR-0013: Tatook Projects: Project Management, M&E, Data Collection and Procurement in one application

- Status: **Accepted** (2026-11-15), option B. Step 1 of §11 (M&E and Data Collection moved into
  `project-management`, `mel-app` retired) is **done**, uncommitted; see §14. Decisions 2–5 in §13
  were taken as recommended here and can still be revisited.
- Supersedes: ADR-0011 §2 (M&E as its own application and database) and ADR-0012 §2
  (Data Collection inside `mel-app`). Their domain designs (schemas, calculation rules, workflow,
  sync protocol) stay valid and move with the code.

## 1. What exists today (inspection, 2026-11-15)

### 1.1 Platform

- Every product is a Laravel 13 + Inertia/React app with its **own PostgreSQL database**, signed in
  through central OIDC (`packages/platform-client`), served at `{workspace}/{path}`, with tenant
  isolation by `BelongsToTenant` (fails closed) and permissions from a versioned catalog
  (`platform/catalog.json`) evaluated by the central effective-access API.
- Products are sold as **ERP products** in `central-app/database/erp-products.json`. A product
  bundles applications as `required` or `optional`, and plans can carry features.
- Cross-app integration is **events only**: outbox in the sender, platform-signed event token, an
  idempotent inbox in the receiver (`docs/shared-modules.md`). A token is issued only while the
  organization has both apps, so "works alone, integrates when both are subscribed" already exists.
  Contracts live in `contracts/events/`.
- Central owns identity, organizations, memberships, invitations, departments/branches (Structure
  module), roles, subscriptions and audit.

### 1.2 Applications relevant to this brief

| App | Code / path | State | Notes |
|---|---|---|---|
| Project Management | `project-management`, app `projects`, `/projects` | **Live in production** since 2026-11-11 (5490d5a). 673 feature tests. Catalog 2.0.0 | Large: see 1.3 |
| M&E | `mel-app`, app `mel`, `/mel` | Built 2026-11-14 (e81714e..f7c9b90), **committed locally, never pushed or deployed**. Catalog 1.1.0 | ADR-0011, own DB |
| Data Collection | `mel-app/app/Collect` | **Work in progress**: foundation, form engine, form and assignment backend (05e6d31). No mobile app yet | ADR-0012 |
| Procurement | inside `project-management` | **Live** (phase 16) | See 1.3 |
| Finance | `finance-app`, `/finance` | Live | Owns books, suppliers (AP), payment requests, petty cash funds |
| Payroll | `payroll-app`, `/payroll` | Phase 1 built, not deployed | No notion of projects |
| HR | `hr-app` | Exists | Employees; out of scope here |

### 1.3 What Project Management already does

| Area | Existing (tables / classes) |
|---|---|
| Projects, programmes | `projects`, `programmes` (group of projects, no money of its own), `ProjectMetrics` (progress, health) |
| Work | `milestones`, `deliverables`, `activities`, `tasks` with dependencies, checklists, collaborators, attachments, comments; work structure (`workspaces`/`spaces`/`folders`/`task_lists`), list statuses, time entries/timesheets, My Work |
| Team | `people` (not necessarily users: consultants, volunteers), `project_members` with allocation %, `org_units` |
| Risks, changes | `risks`, `change_requests` (submitted/approved/rejected/withdrawn, incl. budget restarts) |
| Funding | `funders`, `funder_contacts`, `grants` (amount, currency, restricted, portal sharing flags), `grant_allocations` (grant → project, FX rate), `grant_disbursements` (schedule and receipts), `grant_reports` (kind, due date, submitted / accepted / revision requested, documents, messages), `grant_amendments`, funding listings / marketplace, funding interests |
| Budgets and spending | `budget_lines` by cost category, `budget_versions` with approval, `expenses` with approval bands (`approval_policies`, `expense_approvals`), SoD, budget and grant checks at approval, `Spending::pay` records payment, petty cash, staff advances |
| Procurement | `purchase_requests` (+ lines), `purchase_quotes`, `purchase_orders`, `goods_receipts` (+ lines). A PO's commitment **is** an `Expense`, so it can't be counted twice. Requester ≠ decider, PO raiser ≠ approver/payer, no payment before goods and invoice, partial deliveries |
| Donors | `DonorProjection` (reviewed field-level read model), donor portal for confined funder contacts, donor dashboard and "donor work" across beneficiaries, `partnerships` (the one cross-tenant record: donor org ↔ beneficiary/implementer org, by email token, direct invite, or a workspace the donor creates and someone claims) |
| Documents | folders, versions, private disk |
| Finance link | Ledger removed 2026-11-10 (irreversible migration). Sends `finance.project_expense.paid/voided`, `finance.grant.received`, `finance.staff_advance.*`, `finance.cash.*`, `finance.petty_cash.transferred` through the outbox. **No inbox: nothing comes back from Finance** |
| M&E link | Sends `mel.project.updated` snapshots to `mel-app` every 15 minutes |

**Uncommitted in the working tree (PM):** an organization-level **account type** (`funder` or
`implementer`, `RequireAccountType` middleware, menu depends on it). Central also stores
`organizations.organization_type` (donor / organization) from sign-up (committed in 308bba2).

### 1.4 What M&E and Data Collection already do (`mel-app`)

Programmes, reporting units (optionally linked to a PM project by snapshot), periods, programme
access grants, result levels and results (logframe), indicators with immutable versions,
disaggregation with reconciliation rules, baselines and targets with supersession, submissions,
spreadsheet import (idempotent), evidence, the full verification workflow writing
`verified_results`, calculation rules that never sum incompatible measures, dashboard, six reports,
append-only audit. Data Collection: form definition schema v1, logic/validation engine, form
versions, assignments (ADR-0012 §3–7). Not built: field app, sync API, collected-data review,
indicator mapping, exports, evaluations.

## 2. Findings that shape the decision

1. **Procurement is not missing.** It exists in PM and is live. The work is to deepen it, not
   create it.
2. **M&E exists but is in the wrong place for this brief**, and it has never been deployed. There
   is no production M&E data, so moving it costs code work only, no data migration. This will not
   stay true for long.
3. **Two apps duplicate concepts.** Both have `programmes`. Both have "partners" (M&E `partners`,
   PM funders and partnerships). M&E keeps a stale snapshot of PM projects and milestones
   (`linked_projects`, `linked_milestones`) refreshed by a 15-minute job. An indicator can't point
   at a PM activity, budget line or grant, and a donor can't see results in the PM donor portal
   (ADR-0011 deferred it for this reason).
4. **The brief's organization model conflicts with uncommitted work.** "Do not create permanent
   donor or implementing-partner accounts; an organization may be a donor in one project and an
   implementer in another; classification grants nothing." The new PM `account_type` does exactly
   what the brief forbids: one organization-wide role that changes the menu.
5. **PM's partnership is organization-level, not project-level.** A partnership links two
   organizations. There is no record of "organization X takes part in project P as lead
   implementer, funded through agreement G, under organization Y". Multi-level chains (donor →
   lead → sub-partner) can't be expressed.
6. **PM still performs Finance functions** for organizations without Finance: recording payments
   (`Spending::pay`), petty cash, staff advances. Petty cash already defers to Finance when Finance
   is connected (`FinanceLink`). Payments and advances don't yet.
7. **Nothing flows from Finance to Projects.** Budget utilisation in PM is PM's own spending
   records, labelled the same whether or not Finance posted them.
8. **Payroll has no project dimension.** Labour cost can't reach a project budget today.
9. **Departments exist twice:** central Structure `departments` and PM `org_units`.

## 3. Options considered

| | A. One product, two apps | B. Fold M&E into Projects (**recommended**) |
|---|---|---|
| Shape | Keep `mel-app` separate. Sell "Tatook Projects" as one ERP product bundling `projects` + `mel`. Add a shared navigation shell so `/projects` and `/mel` feel like one app | Move `App\Mel` and `App\Collect` into `project-management` as internal domains, tables in the PM database. Retire `mel-app` before it is ever deployed |
| Meets "one coherent application" | Partly. Two SPAs, two sessions, two catalogs, duplicated programmes and partners, snapshots | Yes |
| Indicator ↔ project, activity, milestone, grant | Snapshots and event contracts for every new link | Plain foreign keys |
| Donor portal shows verified results | New event + projection (deferred in ADR-0011) | A query in `DonorProjection` |
| Data migration | None | **None** (M&E has no production data) |
| Ops | Two databases, pools, queues, schedulers | One |
| Selling M&E without Projects | Natural | Through plan features: an M&E-only plan hides PM modules (PM already gates modules per organization in `App\Support\Modules`) |
| Risk | Low now, high integration cost later | One-off port into a live app; additive tables only |
| Reverses | Nothing | ADR-0011 §2 and ADR-0012 §2, approved 2026-11-14 |

**Recommendation: B.** The brief asks for a modular monolith and warns against extra databases.
Since M&E is undeployed, this is the cheapest moment it will ever be to merge. `App\Mel` and
`App\Collect` are already separate namespaces with their own tables, so they keep a clear boundary
inside PM and could be split out again.

Finance and Payroll stay separate applications in both options.

## 4. Target structure (option B)

One Laravel app, `project-management`, application code `projects`, product name **Tatook
Projects**, path `/projects`, one PostgreSQL database. Internal domains, each with its own
namespace, tables (prefixed where names could clash), actions, policies and tests:

| Domain | Namespace | Owns |
|---|---|---|
| Projects | `App\Actions`, `App\Models` (existing) | programmes, projects, work plans, milestones, deliverables, activities, tasks, risks, team |
| Funding & budgets | existing | funders, funding agreements (grants), allocations, disbursement schedules, budgets, budget versions |
| Collaboration | new `App\Collaboration` | project participants, partner reports, requests, donor review |
| Procurement | `App\Procurement` (move from `App\Actions\Procurement`) | plans, requisitions, RFQs, quotes, evaluations, POs, receipts |
| M&E | `App\Mel` (moved) | frameworks, indicators, targets, submissions, verified results, evaluations later |
| Data Collection | `App\Collect` (moved, under M&E) | forms, versions, assignments, collected submissions, review, mappings |
| Integrations | `App\Integrations` (`FinanceEvents`, `FinanceLink` move here) | outbox events out, a new inbox in, external references |
| Reporting | `App\Reporting` | dashboards, exports, donor projections |

Boundary rule (Pest architecture test, as in central): a domain reaches another domain's tables only
through its actions or read classes, never by querying its models from a controller.

## 5. Data ownership matrix

| Record | System of record | Others hold |
|---|---|---|
| Users, organizations, memberships, invitations to staff, departments | Central | Projects mirrors users/orgs by platform id (existing bridge) |
| Organization classification (NGO, government, business, foundation, international organization, educational, other) | Central | Read only. **Grants no permission** |
| Project participation and responsibilities | Projects | — |
| Projects, activities, milestones, teams, risks | Projects | — |
| Funding agreements, allocations, disbursement **schedules**, budgets | Projects | Finance receives grant receipts as events |
| Requisitions, RFQs, quotes, POs, goods receipts, commitments | Projects | Finance receives payable/commitment events |
| Suppliers | Finance when connected; Projects' own list otherwise (existing `App\Models\Supplier`) | Projects keeps a reference (`finance_supplier_id`) |
| Invoices, payments, journals, posted expenditure, cash, banks, advances, petty cash funds | **Finance** | Projects keeps status and reference only. Without Finance, Projects keeps an operational spending log clearly labelled "recorded in Projects, not accounting" |
| Salaries, payslips, deductions, payroll runs | **Payroll** | Projects receives aggregated labour-cost allocations only |
| Indicators, targets, verified results, forms, collected data | Projects (M&E domain) | — |

## 6. Database changes (all additive to the PM database)

**Moved from `mel-app`** (same schemas as ADR-0011 §3 and ADR-0012 §6, reworked before first use):

- `mel_programmes` is **dropped**: frameworks hang off PM `programmes` or a single `project`.
  `programme_units.linked_project_id` becomes a real FK `project_id`. *As built (§14):
  `mel_programmes` was kept as M&E's own programme record; units link to projects through
  `linked_project_id`, now a real FK.*
- `linked_projects`, `linked_milestones`, the inbox and `mel_project_snapshots` are dropped.
  `results.linked_milestone_id` becomes an FK to `milestones`. Optional FK `activity_id` on results.
- M&E `partners` becomes a reference to a project participant (below).
- Tables keep `mel_` / `collect_` prefixes where a name clashes (`submissions`, `audit_events`,
  `locations`, `dimensions`).
- The M&E audit trail writes into PM's existing `audit_events` (append-only trigger added there).

**New, Collaboration:**

- `project_participants`: project, `organization_id` (platform org, may be another tenant),
  `participant_role` (donor, lead implementer, implementer, sub-implementer, auditor, technical
  partner, other), `parent_participant_id` (who they report to, so chains of any depth),
  `funding_agreement_id` (nullable), status (invited, active, ended), invited/accepted by and when.
  The project lives in **one owning tenant**. Participants from other tenants reach it only through
  this row plus a projection (generalising `DonorProjection` into `ParticipantProjection`).
- `project_participant_invitations`: token hash, expiry, revocation, accepted by an authorised
  representative of the invited organization. Reuses the three entry paths of `partnerships`
  (email, direct, claimable workspace). Existing `partnerships` rows are migrated into
  participations for each grant-linked project, then `partnerships` stays as the
  organization-to-organization connection only.
- `participant_grants`: what each participant may see or do per module (reports, budget,
  procurement summaries, results, documents), replacing the per-grant `portal_*` booleans over
  time.
- `partner_reports` (+ `partner_report_versions`, `partner_report_reviews`): type (activity,
  monthly, quarterly, financial, M&E, final), period, draft / submitted / revision requested /
  approved / published, immutable versions, consolidation link to the lead's report. `grant_reports`
  is migrated in.
- `requests` (+ `request_decisions`): type (funding, budget revision, activity change, timeline
  extension, procurement, payment, information), routed by a `request_routes` table (project,
  type, amount band → deciding participant and permission). `change_requests` is migrated in.

**New, Procurement:** `procurement_plans` and `procurement_plan_items` (goods/works/services, method,
estimate, budget reference, officer, planned dates); `rfqs`, `rfq_suppliers`, `quote_lines` (prices
per line), `evaluations` and `evaluation_scores` (technical and commercial criteria);
`procurement_rules` (per organization, overridable per funding agreement: required quotes by amount
band, whether the PO raiser may receive goods). `purchase_requests.project_id` becomes nullable for
non-project buying, with a `cost_centre` when there is no project.

**New, Integrations:** `external_references` (local record, app, external id, kind, status,
synced_at) and an `inbox` for events from Finance and Payroll.

**Removed later, not now:** PM `account_type` (uncommitted, see §8), `org_units` once central
departments are read through the platform API.

## 7. Integration contracts

**Finance (Phase 5).** New events, schemas in `contracts/events/`:

| Event | Direction | When |
|---|---|---|
| `finance.commitment.raised` / `.released` v1 | Projects → Finance | PO approved / cancelled or fully invoiced. A commitment, never a posting |
| `finance.payment_request.raised` v1 | Projects → Finance | Approved expense or PO invoice when Finance is connected. Replaces `Spending::pay` for those organizations, as petty cash already does |
| `projects.expense.posted` v1 | Finance → Projects | Invoice posted or payment made against a project reference. Carries Finance's reference and amount |
| `projects.funding.received` v1 | Finance → Projects | Grant receipt booked in Finance |

Budget utilisation then shows three separate figures, never summed: **committed** (open POs),
**posted** (from Finance, labelled verified) and, without Finance, **recorded in Projects**
(labelled as such). A posted invoice releases its commitment in the same step, so nothing counts
twice.

**Payroll (Phase 5).** Payroll gains an optional project allocation per employee (project, budget
line, percent). When a run is approved it sends `projects.labour_cost.allocated` v1: per project,
budget line and period, a **total only**, never per person. Allocations with fewer than a set number
of people (default 3) are shown only to holders of `projects.finance.view`, since a total over one
person is that person's salary. Finance already posts payroll from `finance.payroll.posted`; Projects
counts labour cost from Payroll only when Finance is not connected, and from Finance's posting when
it is, so labour is never recognised twice.

**Projects without Finance or Payroll** keeps working exactly as today.

## 8. Security and authorization

- Checks stay permission-based (`->can()` in routes and again in actions). No check on role names or
  on organization classification.
- The catalog becomes `projects` **3.0.0**: PM's permissions plus `mel.*` and `mel.collect.*`
  (the keys stay as they are, so tested code isn't renamed) plus new
  `procurement.plan`, `procurement.evaluate`, `procurement.receive`, `collaboration.*`,
  `requests.*`. M&E roles join PM's roles. Plan features gate modules (`mel`, `collect`,
  `procurement`).
- **Project-scoped access** is new: today access is organization-wide (phase 16 limit). Add a
  `ProjectScope` used by every domain: organization-wide holders see all, others see projects where
  they are a member or their organization is a participant. M&E's `programme_access` folds into it.
- **Participants from other tenants** never become members of the owning workspace with broad
  roles. They act through `project_participants` + `participant_grants`, read through the reviewed
  projection, and write only into their own reports and requests.
- **Separation of duties**, configurable per organization and funding agreement: requester ≠
  approver (exists), PO raiser ≠ receiver (new, default on), receiver ≠ payer (Finance), verifier ≠
  submitter (M&E, exists).
- **The uncommitted `account_type` work should not be committed as is.** It makes donor vs
  implementer an organization-wide setting. Recommended replacement: the menu shows Donor oversight
  when the organization is a donor participant in at least one project, and implementer sections
  when it owns or implements one.
- Beneficiary personal data in Data Collection stays behind `mel.collect.personal_data`, never in
  donor projections.

## 9. Navigation

The brief's structure (Overview, Projects, Procurement, Monitoring & Evaluation, Collaboration,
Administration), built in PM's existing `resources/js/lib/navigation.ts` and `product-layout.tsx`,
filtered by permission, plan feature and participation. M&E pages move from `mel-app/resources/js`
into PM's page tree. Existing PM URLs keep working (redirects where a page moves).

## 10. MVP and what is deferred

**MVP (Phases 2–4):** M&E and Data Collection inside Projects with direct links to projects,
milestones and activities; the merged catalog and navigation; project participants with
multi-level chains and invitations; project-scoped access; procurement plans, configurable quote
rules, line prices, PO raiser ≠ receiver, non-project requisitions; requisitions in the Approvals
queue; partner reports with versions; typed requests with routing; verified M&E results in the donor
portal.

**Deferred:** Finance two-way contract and Payroll allocation (Phase 5); RFQ supplier portal (suppliers
submitting quotes themselves); weighted bid evaluation; evaluations module (ADR-0011 §3 Phase 2);
report consolidation across sub-partners beyond linking; Kobo/ODK adapters; GIS; reading central
departments in place of `org_units`; PO PDF.

## 11. Implementation plan

Each step is a local commit on its own branch, with the full PM suite green. Nothing deploys until
asked.

1. **Clean the working tree first.** The current branch `products/mel-collect` carries ~57
   unrelated uncommitted files (access page, petrol roles, PM account type). Commit or park them so
   this work starts from a clean base.
2. **Port M&E into PM** (Phase 2): move `App\Mel`, models, migrations (reworked per §6), pages,
   tests. Drop snapshots and the PM→M&E event. M&E tests run inside PM's suite.
3. **Port Data Collection's WIP** (05e6d31) the same way, then continue ADR-0012's sequence inside
   PM (sync API, review, mappings, field app `projects-mobile-app` or `mel-mobile-app` against the
   PM API).
4. **Catalog 3.0.0, plan features, navigation.** Central: `erp-products.json` drops the
   `monitoring_evaluation` product's `mel` app (M&E-only becomes a Projects plan), marketing catalog
   updated. `mel-app` removed from CI, ops and the dev proxy (never deployed, so nothing to retire on
   the server).
5. **Project participants and project scope** (Phase 2/4), with the `partnerships` and
   `grant_reports`/`change_requests` data migrations.
6. **Procurement depth** (Phase 3).
7. **Partner reports, requests, donor oversight** (Phase 4).
8. **Finance and Payroll contracts** (Phase 5), each app tested on both sides.
9. **Stabilisation** (Phase 6): PostgreSQL run, browser walk-through, runbook.

## 12. Testing, migration and rollback

- **Before any production migration:** a verified PM database backup (`docs/runbooks/backup-restore.md`),
  restored once to a scratch database to prove it works.
- Every migration is additive and reversible (`down` tested up/down/up), except the planned data
  copies (`partnerships`, `grant_reports`, `change_requests` → new tables), which **keep the old
  tables** until a later release confirms counts match. A migration test asserts row counts and
  sums before and after.
- Suites: PM (673 today) on SQLite and PostgreSQL 17; the ported M&E/Collect tests; new tests for
  participation chains, cross-tenant participant isolation, project scope, procurement SoD and
  rules, idempotent sync, report and request routing, and the Finance/Payroll contracts on both
  sides (as `ProjectEventsTest` does today).
- Rollback per deploy: previous release symlink plus the backup. Catalog changes need
  `platform:catalog-reassign projects` and are reversible by publishing the previous version.

## 13. Risks and open decisions

**Risks**

- Porting into a live app: mitigated by additive migrations, feature flags via plan features, and
  the full suite.
- PostgreSQL-only triggers (M&E append-only and locked rows) are still unverified locally: the local
  PostgreSQL password is unknown.
- Cross-tenant participation is the most security-sensitive change in the brief. It needs its own
  review before deploy.
- Project scope changes who sees what in a live app. Organization-wide holders keep today's view, so
  only new restricted users are affected.
- Payroll totals over very few people reveal salaries (§7).

**Decisions needed from the owner**

1. **Option B (fold M&E and Data Collection into Projects)** or A (keep `mel-app`, bundle and share
   the shell)? B reverses the 2026-11-14 decision.
2. If B: is an **M&E-only plan** of Tatook Projects acceptable in place of a separate M&E product?
3. **Drop the uncommitted PM `account_type`** in favour of participation-driven menus?
4. **Where a multi-organization project lives:** one owning tenant with participants reading through
   projections (recommended), or a copy per organization linked by events?
5. For organizations **with Finance**, should Projects stop recording payments and staff advances
   itself (as petty cash already does)?

## 14. As built (2026-11-15)

**Done** (uncommitted on `products/mel-collect`):

- `App\Mel` and `App\Collect` live in `project-management`, models in `App\Mel\Models` and
  `App\Collect\Models`, tables `mel_*` and `collect_*` in the Projects database (migrations
  `2026_11_15_100000`–`100400`). Tenancy is PM's `BelongsToOrganization`. M&E's workspace record is
  `mel_settings`, made on first use with the default dimensions (`SetUpMel`).
- `linked_projects`, `linked_milestones`, `mel_project_snapshots`, the M&E inbox, the
  `mel.project.updated` event and `projects:publish-to-mel` are gone. Units and results point at
  `projects` and `milestones` with real (tenant) foreign keys, and project figures are read live
  (`App\Mel\LinkedProjects`, `ProjectMetrics`).
- **Deviation from §6:** `mel_programmes` was kept as M&E's programme record, not replaced by PM
  programmes. Moving frameworks onto PM programmes is a later, separate change.
- Pages at `{workspace}/projects/mel/*`, route names `mel.*`, behind module `mel`
  (`App\Support\Modules`) and the M&E permissions. Navigation has a Monitoring & evaluation group;
  M&E settings and audit trail sit under Administration.
- Catalog `projects` **3.0.0**: M&E permissions, the nine M&E roles and their SoD rules added.
  Existing roles are unchanged: owners hold everything, others need an M&E role.
- Central: the M&E product and the `mel-standard` plan grant `projects`; NGO no longer offers `mel`;
  marketing points M&E at Projects. CI, nginx, php-fpm and local Apache entries for `mel` removed;
  Projects' upload limit (22 MB) and timeouts (120 s) raised. `mel-app/` deleted (it stays in git
  history up to `05e6d31`). Runbook: `docs/runbooks/mel.md`.
- PostgreSQL triggers are now verified locally (the M&E suite runs on `projects_test`).

**Left:**

- An **M&E-only plan** is a plan of Projects today; gating the other PM menus by plan feature for it
  is not built.
- Procurement depth, project participants and cross-organization participation (§4, §8).
- Finance and Payroll contracts (§7).
- Moving frameworks onto PM programmes (the §6 deviation).
- The Data Collection field app (ADR-0012 §7) will talk to Projects, not `mel-app`.
