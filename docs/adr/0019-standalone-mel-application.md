# ADR-0019: Tatook M&E as a standalone application, extracted from Projects

- Status: **Accepted** (2026-11-16), with the recommended answer to each open decision (§15).
  M1–M7 are built and committed locally on `products/mel-collect` (§16–§25). **Nothing is
  deployed**, and nothing has run on a device, in staging or in production.
- Would supersede: ADR-0013 for M&E and Data Collection, which merged them into
  `project-management`. ADR-0011 (M&E domain) and ADR-0012 (Data Collection: engine, mobile app, sync
  protocol) stay valid and are the domain design this ADR builds on.
- Decision taken by the owner: M&E becomes its own application, with its own backend, database,
  web app, Android-first mobile app and desktop experience. It also gets its own subscription and
  trial. Project Management and Finance links are optional.

## 1. Current architecture (inspection, 2026-11-15)

### 1.1 Where M&E is

- `project-management/app/Mel`, `app/Collect` and `app/Http/Controllers/Mel`: about 10,100 lines of
  PHP.
- 21 page files in `resources/js/pages/mel`: about 8,900 lines.
- `resources/js/mel/collect`, the TypeScript form engine: `definition.ts`, `rules.ts`, `builder.ts`
  and `renderer.tsx`, about 1,800 lines with tests.
- Tables `mel_*` and `collect_*`, from migrations `2026_11_15_100000` to `100400`.
- Tests: `tests/Feature/Mel`, `tests/Unit/Mel`, and the conformance cases in
  `contracts/collect/rules-cases.json`. PHP `App\Collect\Rules` and TS `rules.ts` both run these.

### 1.2 It has never left this machine

- The merge (673d44d) and everything after it (7eebb1f, b6e24c1, 9e80275, a288bdc) exist only on
  the local branch `products/mel-collect`. **No remote branch contains 673d44d.**
- The old `mel-app` was never pushed or deployed either.
- So no production database is expected to hold `mel_*` or `collect_*` rows. Check this read-only
  on the server before cutover (§4.4).
- Consequence: **the extraction moves code, not data.**

### 1.3 Code now versus history

- `mel-app` exists in history up to 05e6d31: backend e81714e, pages 1643331, wiring d0dc055,
  snapshots 19b2423, Collect foundation 05e6d31.
- The current code is ahead of it. Since the merge it gained:
  - live project figures with access checks (7eebb1f);
  - one set of numbers;
  - partner-report snapshots (b6e24c1).
- So extract **the current code**. Take only the app scaffold from history (§3).
- Git recorded the merge as renames (235 files). Moving the files back keeps
  `git log --follow` working.

### 1.4 What M&E uses from Projects (to replace)

| Dependency | Where | Replacement in `mel-app` |
|---|---|---|
| Tenancy `App\Tenancy\BelongsToOrganization` | `App\Mel\Models\Concerns\TenantModel` | The `TenantModel` of `mel-app`'s history, over platform-client tenancy (`workspaces`) |
| `App\Models\User` (33 uses) | everywhere | `mel-app`'s own user mirror (platform-client `user_model`) |
| `organization_memberships` | `App\Mel\Members` | `mel-app`'s member mirror (`WorkspaceMember` in history) |
| `Project`, `ProjectMetrics`, `ProjectFinance`, `ProjectVisibility` | `App\Mel\LinkedProjects` | The Projects integration API (§9) |
| `Project`, `Milestone` pickers | M&E programme setup and framework controllers | The Projects integration API (§9) |
| `App\Support\Spreadsheet`, `TenantSchema` | import, migrations | Copied; both existed in `mel-app` |
| FK `mel_programme_units.linked_project_id → projects` | migration 100200 | Column kept, FK removed, external reference (§4) |
| FK `mel_results.linked_milestone_id → milestones` | migration 100200 | Same |
| `SetUpMel`, `Modules::MEL` | PM middleware and modules | `mel-app` sets itself up on provisioning; no module switch |

### 1.5 What Projects uses from M&E (to cut)

- **`App\Collaboration\ReportContent::mel()`.** A partner report captures, at submission, the
  verified results of units linked to the project. Its replacement is the M&E read API (§9).
- **Wiring:**
  - `routes/web.php:65`, `routes/web/mel.php`;
  - `SetUpMel`, `Modules::MEL` and the module list in `Admin\SettingsController`;
  - the Monitoring & evaluation group in `resources/js/lib/navigation.ts`;
  - `HandleInertiaRequests` (modules).
- **Catalog.** `platform/catalog.json` 3.3.0 holds the M&E permissions and the nine `mel_*` roles.
  The twelve PM roles hold no `mel.*` permission, so the split is clean.

### 1.6 Central today

- The ERP product `monitoring_evaluation` requires `projects`.
- The plan `mel-standard` grants `['projects']` (`LocalPlansSeeder.php:48`).
- The marketing entry `monitoring-evaluation` registers as application `projects`.
- Trials are kept per application (`ManageEntitlements::startTrial`). So a `mel` application gets its
  own trial with no Central billing change.
- **The platform gap.** `EventTokenIssuer` signs tokens only for a shared module audience, plus the
  one exception `MODULE_TO_PRODUCT = ['finance' => ['projects']]`. No token exists between two
  products, or for a read on behalf of a user. The Projects link (§9) needs one.

### 1.7 Mobile and desktop

- Four Expo SDK 57 / React Native 0.86 apps share the same sign-in, workspace choice and
  secure-store pattern:
  - `petrol-mobile-app`, which has the `tryLater` error classification;
  - `pharmacy-mobile-app`, which has `src/offline` (outbox, sync, connectivity);
  - `restaurant-mobile-app`;
  - `retail-mobile-app`, which uses `expo-sqlite`.
- ADR-0012 §8–9 already specifies `mel-mobile-app`: storage, screens, sync protocol, recovery table.
- `rules.ts` is written to be copied as-is into the field app ("no imports beyond ./definition").
- Desktop: `pharmacy-electron-app` and `restaurant-electron-app` (Electron) exist. They are offline
  point-of-sale apps with local databases.

### 1.8 Finance

- M&E has no Finance link. It shows a project's approved budget and paid amount through PM's
  `ProjectFinance`, and only with `projects.finance.view`.
- The Projects ⇄ Finance integration (ADR-0017, a288bdc) is unaffected by this ADR.

## 2. Target architecture

```text
central-app/          identity, organizations, subscriptions, trials, catalogs, tokens
project-management/   Projects (app `projects`): no M&E code after cutover
mel-app/              M&E (app `mel`): Laravel 13 + Inertia/React, platform-client,
                      PostgreSQL database `mel`, served at {workspace}/mel
mel-mobile-app/       Expo, Android first, the same backend (api/v1)
```

- **One backend.** `mel-app` is a modular monolith. The web app, the mobile app and the desktop PWA
  all use it. There is no separate mobile or desktop backend.
- **Namespaces stay as they are:**
  - `App\Mel`: programmes, frameworks, indicators, targets, periods, submissions, review,
    performance, reporting, audit;
  - `App\Collect`: forms, versions, rules, assignments, scope.

  The brief's folder names (Programmes, ResultsFramework, Indicators, …) already exist as classes.
  Renaming them would be churn with no behaviour change.
- **New in `mel-app`:**
  - `App\Integrations\Projects` (client, references, failure handling);
  - `App\Api\V1` (field app API);
  - later, `App\Mel\Evaluations` (Phase 2).
- **No `mel-desktop/` folder.** See §7.

## 3. Reuse matrix

| Part | Source | Action |
|---|---|---|
| M&E domain (`App\Mel`, 20+ classes) | `project-management`, current | Move as is; only the dependencies in §1.4 change |
| Collect domain (`App\Collect`) | `project-management`, current | Move as is |
| M&E and Collect controllers | `app/Http/Controllers/Mel` | Move; route names `mel.*` stay; the path becomes `/mel` |
| Pages (21) and `resources/js/mel` | `project-management` | Move; Wayfinder regenerated |
| Form engine (PHP and TS) and conformance cases | `App\Collect\Rules`, `rules.ts`, `contracts/collect` | Keep both. The mobile app carries a copy of `rules.ts` and runs the same cases |
| Tests | `tests/Feature/Mel`, `tests/Unit/Mel` | Move; replace PM factories and helpers |
| App scaffold: composer, bootstrap, tenancy, user and member mirror, `HandleInertiaRequests`, `RememberMember`, layout, Vite | `mel-app` at 05e6d31 | Restore from history, then update to today's platform-client |
| Platform wiring: CI job, nginx, php-fpm, local Apache, runbook | d0dc055 | Restore, update |
| Snapshot sync (`linked_projects`, `mel.project.updated`, inbox) | 19b2423 | **Do not restore.** Replaced by live reads (§9) |
| Mobile scaffold | `petrol-mobile-app` (ADR-0012 §8) | Copy, as for the petrol app |
| Offline outbox and sync | `pharmacy-mobile-app/src/offline`, petrol `tryLater` | Reuse the patterns. Collect's protocol (ADR-0012 §9) is the contract |
| UI components | `packages/ui` | Use as the other apps do |

## 4. Database extraction and migration

### 4.1 Schema

- `mel-app` gets its own PostgreSQL database `mel`. Its migrations are the current PM migrations
  `2026_11_15_100000` to `100400`, re-dated and changed as follows:
  - **tenant key:** `organization_id` is the platform organization's id, with no foreign key
    (organizations live in Central). `workspace_members` comes from `mel-app`'s history;
  - **cross-application links:** `linked_project_id` and `linked_milestone_id` keep their names but
    lose their foreign keys. They hold Projects' UUIDs, which are stable. M3 adds `linked_label` and
    `linked_at`, written when the link is made, so historical reports still name a deleted project.
    Optional `linked_activity_id` (Phase 2);
  - **users:** `constrained('users')` points at `mel-app`'s user mirror;
  - **audit:** the PostgreSQL append-only and locked-row triggers move unchanged. They were already
    verified on `projects_test`.
- No foreign key crosses databases. Identifiers (UUIDv7) are kept.

### 4.2 Data

- **Production: none** (§1.2).
- **Local development data:** an optional, local-only script copies `mel_*` and `collect_*` rows
  from the `projects` dev database into `mel`. It keeps ids, maps organizations to workspaces by
  platform id, and refuses to run unless `APP_ENV=local`. It is not needed if the dev data can be
  re-seeded.

### 4.3 One writable copy

- From the moment `mel-app` is deployed, M&E records are written only there.
- The PM release that removes M&E (routes, pages, navigation, catalog permissions) goes out **before
  or with** `mel-app`'s first deploy, never after it.
- PM's `mel_*` and `collect_*` tables stay in its schema, unused. A later migration drops them, and
  it **refuses to run if any row exists**.

### 4.4 Before cutover (read-only checks)

- On the production PM database:
  `SELECT to_regclass('mel_programmes'), to_regclass('collect_forms');`.
  The expected answer is NULL. If either returns a table, count rows and stop for a data plan.
- Locally: run `mel-app`'s suite and the migration's up/down/up on PostgreSQL.

### 4.5 Rollback

- Before production data exists: roll back the `mel-app` release and its Central registration (the
  application is unregistered or set to beta).
- After production data exists: release rollback only. The `mel` database is backed up like the
  others (`docs/runbooks/backup-restore.md`), and M&E is never moved back into PM.

## 5. Web application

- Paths are `{workspace}/mel/*`. Route names stay `mel.*`.
- Navigation, filtered by permission:

| Group | Contents |
|---|---|
| Dashboard | M&E home |
| Programmes | Programmes and their units and periods |
| Results frameworks | Frameworks |
| Indicators | Indicators |
| Monitoring | Data entry, import, verification |
| Evaluations | Phase 2. Not shown until built |
| Data collection | Forms, assignments, collected submissions and their review |
| Reports | M&E reports |
| Teams and assignments | Programme access, field teams |
| Settings | Settings, audit trail |

- **No duplicated screens.** The pages move; they are not copied. The "Programmes" and "M&E
  programmes" clash in PM disappears, because each app has one programme concept.
- **Linked project sections** show only when the organization has both apps and the viewer has
  `projects.view` (§9).

## 6. Mobile offline architecture

`mel-mobile-app` is built exactly as ADR-0012 §8–9 specifies. The essentials:

### 6.1 Sign-in and storage

- **Sign-in:**
  - Central OIDC with the `mel` mobile client (PKCE), as the other mobile apps;
  - Google sign-in through Central;
  - workspace choice;
  - tokens in `expo-secure-store`.
- **Storage:**
  - `expo-sqlite` with SQLCipher. The random key is in secure store. Check the SDK 57 plugin
    options against the versioned docs first.
  - Tables: `form_versions`, `assignments`, `drafts`, `queue`, `attachments`, `history`.
  - Each record is scoped to `workspace|user`.
  - The draft is saved on every change, in a transaction.
  - Nothing unsent lives in memory or AsyncStorage.
- **Validation offline:** the copied `rules.ts` runs the shared conformance cases in the mobile CI.
- **Attachments:**
  - photos are files in the private directory, re-encoded (EXIF stripped);
  - GPS shows its accuracy and needs the OS permission and the form's consent question.

### 6.2 API v1 (`mel-app`)

- **Downloads:**
  - `GET api/v1/collect/assignments`;
  - `GET api/v1/collect/form-versions/{id}`, refused for an app too old for the form.
- **`POST api/v1/collect/submissions`** with a client UUIDv7 `id` and a content hash:
  - same id and same hash → `200 replayed`;
  - same id and a different hash → `409 id_conflict`.
- **`PUT api/v1/collect/submissions/{id}/attachments/{question}`:**
  - sha256 and size are checked against the manifest;
  - a repeat is a no-op;
  - `receiving → submitted` happens when the last file arrives.
- **`GET api/v1/collect/submissions/{id}`** returns the status and the files still missing.

### 6.3 Sync rules

- A request with no reply is never treated as failed or as done. The next attempt re-sends the
  same id.
- Phone states: `Draft → Ready → Syncing → Synced`, plus **Failed** (retried) and **Action
  required** (a person must act).
- Retries use backoff with jitter (30 s to 30 min).
- Errors: offline, 429 and 5xx are retried; 401 refreshes once; 403, 409 and 422 become Action
  required.
- ADR-0012 §9's recovery table applies: interrupted upload, expired session, revoked assignment,
  closed version, corrupted draft, sign-out with unsent data.

### 6.4 When it counts as offline-ready

Only after a device test of each case:

- airplane mode throughout;
- the app killed mid-draft and mid-upload;
- the connection dropped between attachments;
- the same submission sent twice;
- an expired token while the queue holds data.

## 7. Desktop recommendation

**An installable PWA of `mel-app`, not a native app, for the MVP.**

- The desktop workflows are all online:
  - programme management;
  - indicator setup;
  - review;
  - analysis;
  - reporting;
  - form design;
  - export.
- None of them needs offline data entry. The PWA adds:
  - a manifest;
  - icons;
  - a service worker that caches static assets only, **never** M&E data.
- No separate React frontend, and no business logic in a client.
- If a real native need appears (very large exports to the file system, offline review), use an
  **Electron** shell loading the web app. Tatook already builds and signs Electron apps; Tauri would
  add a new toolchain. Its precise workflows and security rules are decided before any local sync
  is built.

## 8. Central: SSO, subscription and trial

1. **Register `mel`** with `platform:register-local-product`: catalog, sign-in, service and mobile
   clients, the `/platform/tenants` provisioning endpoint and the webhook secret.
2. **Split the catalog:**
   - `mel-app/platform/catalog.json` `mel` 1.0.0 holds the M&E permissions (`mel.*`,
     `mel.collect.*`, keys unchanged), the nine roles and their separation-of-duties rules;
   - `projects` 4.0.0 drops them.
   - Locally, existing `mel_*` role assignments are moved with `platform:retire-role --scope` or
     re-assigned.
3. **Products and plans:**
   - `erp-products.json`: `monitoring_evaluation` requires `mel`; the NGO ERP lists `mel` as
     optional;
   - `LocalPlansSeeder`: `mel-standard` grants `['mel']`, plus a Projects + M&E bundle;
   - marketing: `monitoring-evaluation` registers as application `mel`;
   - the public copy already presents them as separate apps.
4. **What then works unchanged:**
   - trial per application;
   - subscription expiry and revocation (platform-client entitlement checks);
   - the launcher tile;
   - several organizations per user, through workspace choice.
5. **Not built in M&E:** accounts, passwords and organization registration. These come from
   Central only.

## 9. Project Management integration

### 9.1 Who owns what

- **Projects** owns projects, activities and milestones.
- **M&E** owns frameworks, indicators, evidence and verified results.
- **The link** is held in M&E: unit → project, result → milestone, and optionally result → activity.

### 9.2 Mechanism: live reads, no replication

| Read | Endpoint | Caller | Purpose |
|---|---|---|---|
| Projects | `GET {workspace}/projects/integration/v1/projects?ids=…` | `mel-app` server | label, status, archived/deleted, progress; approved budget and paid only when the acting user has `projects.finance.view` |
| Pickers | `GET …/projects/integration/v1/projects?search=…`, `…/projects/{id}/milestones` | `mel-app` server | projects and milestones the acting user may see (`ProjectVisibility`) |
| Verified results | `GET {workspace}/mel/integration/v1/verified-results?project_id=…&from=…&to=…` | `project-management` server | `ReportContent::mel()` for partner reports |

### 9.3 Authentication: a new, narrow Central rule

- Central gains `PRODUCT_PAIRS = ['projects' => ['mel'], 'mel' => ['projects']]` in
  `EventTokenIssuer`, next to `MODULE_TO_PRODUCT`.
- A token is issued only while:
  - the organization is active;
  - the organization is entitled to **both** apps.
- The token is the usual one (RS256, 300 s, `aud`, `sub`, `org`) plus `act`: the user on whose
  behalf the read is made.
- The receiver verifies the token, then checks **that user's** permissions in its own catalog
  through its own effective-access call. A service token alone never reads anything.
- This needs a security review before deploy.

### 9.4 Failure handling

| Case | Behaviour |
|---|---|
| Project archived | Shown as archived. Link kept |
| Project deleted / not found | Shown as "no longer in Projects" with `linked_label`. Link kept, never removed automatically |
| A subscription revoked (either app) | Central refuses the token. Linked sections are hidden. Links are kept and reappear on renewal |
| A user's access changes | Checked on every read for the acting user. Nothing is cached per user beyond the request |
| Projects unavailable | 3 s timeout. "Projects is unavailable right now" shows in the linked section. Every M&E workflow carries on |
| Duplicate events | No events in the MVP. Reads are idempotent |
| Historical reporting | M&E results never embed Projects figures. A partner report keeps what it captured at submission (`capturedAt`). When M&E is unavailable, it records that no M&E figures were captured |

Events, such as `projects.project.archived`, come only if a real need appears.

## 10. Finance

- M&E does no accounting, payments, petty cash or payroll.
- Project money appears in M&E only through the Projects read (§9). It is labelled as Projects'
  figures, shown only with `projects.finance.view`, and never stored in M&E.
- No Finance contract is added, and the Projects ⇄ Finance integration is not touched.

## 11. Security and offline data protection

- **Tenancy.** Every model is scoped by workspace and fails closed. A cross-tenant test runs on
  every domain, as in the PM suite today.
- **Permissions:**
  - checked in routes and again in actions;
  - data collection access follows the assignment and the scope (`App\Collect\Scope`);
  - a form is visible only to assigned people and form managers.
- **Beneficiary data:**
  - personal answers sit behind `mel.collect.personal_data`;
  - a donor or partner role never sees raw submissions;
  - the verified-results API returns aggregates as verified, never submissions.
- **Attachments.** Private disk, signed short-lived URLs, sha256 checked.
- **Mobile:**
  - an encrypted database;
  - the key in secure store;
  - tokens in secure store, with a 30-day refresh;
  - an app lock after 5 minutes (optional per workspace);
  - per-user scoping on a shared phone;
  - typed confirmation to sign out with unsent data.
- **Cross-product reads:** §9.3.
- **Revoked access:**
  - entitlement checks on every request;
  - a revoked assignment answers 403 → Action required on the phone, and the data stays
    recoverable.
- **Audit.** The append-only M&E audit trail moves with its triggers.

## 12. Roadmap

| Milestone | Content | Ends with |
|---|---|---|
| **M1** | `mel-app` backend and web, extracted, at parity; own database; Central SSO and provisioning (local) | §13 acceptance |
| **M2A** | Central: `mel` registered (catalog `mel` 2.0.0), products, plans, bundle; local proxy, CI, production templates; real-SSO and four-organization verification | §17, done 2026-11-16 |
| **M2B** | PM: M&E removed (routes, pages, navigation, module, catalog `projects` 4.0.0); partner reports without `ReportContent::mel()` until M3 | §18 plan, §19 built 2026-11-16 (not deployed) |
| **M3** | Integration reads (§9) and the product-pair token; `ReportContent` reading M&E over HTTP | Both-apps tests, revoked-subscription and unavailable-Projects tests; security review |
| **M4** | Collect API v1 (§6.2), collected-submission review, separate from verified results | Idempotency, attachment and recovery tests on PostgreSQL |
| **M5** | `mel-mobile-app`, Android | The device checks in §6.4, on a real device |
| **M6** | PWA install | Install verified in a browser |
| Deploy | Read-only checks (§4.4), then PM (without M&E) and `mel-app` together | Owner's approval |

- **Phase 2:** evaluations, advanced donor reporting, exports and analytics, monitoring workflows,
  result → activity links, desktop shell only if needed.
- **Future:** iOS, GIS, assisted analysis, Kobo/ODK adapters.

## 13. First coding milestone (M1) and acceptance criteria

**Scope.**

- Create `mel-app` from its history (05e6d31) scaffold, updated to today's platform-client.
- Move the current `App\Mel`, `App\Collect`, M&E controllers, pages, the form engine and tests from
  `project-management` with `git mv`, so history follows.
- Replace the §1.4 dependencies.
- The Projects link in M1 is **off**: the columns exist and the linked sections are hidden. It
  comes in M3.
- `project-management` keeps its copy, untouched, until M2. Neither copy is deployed.

**Acceptance criteria.**

1. **Database.** `mel-app` runs on its own PostgreSQL database `mel`. `migrate:fresh` and the
   down/up/down cycle pass.
2. **Sign-in.** Locally, through Central SSO: a user signs in at `{workspace}/mel`, chooses among
   several workspaces, and reaches M&E.
3. **Provisioning.** An organization entitled only to `mel`, with no `projects` entitlement, is
   provisioned by Central's `/platform/tenants` call. It can then do everything below without any
   Projects record existing:
   - create a programme, units and periods;
   - build a framework;
   - define indicators with targets;
   - enter, import and verify results;
   - produce reports;
   - design, version and assign forms.
4. **Revoked entitlement.** Revoking the entitlement refuses access. Re-granting it restores the
   data.
5. **Tests.** The ported M&E and Collect suites pass on PostgreSQL, including:
   - cross-tenant tests;
   - append-only audit triggers;
   - the PHP and TS conformance cases (`contracts/collect/rules-cases.json`).
6. **No coupling.** `grep` finds no `App\Models\Project`, `Milestone`, `ProjectFinance` or
   `organization_memberships` in `mel-app`. No migration references a Projects table.
7. **Static checks and builds.** PHPStan (level as PM), Pint, tsc, Vitest and the Vite build are
   clean.
8. **Projects unaffected.** `project-management`'s suite is unchanged and green.

## 14. Complexity and major risks

**Relative complexity, without time estimates:**

| Milestone | Complexity | Why |
|---|---|---|
| M1 | Medium | Mostly moves. The replaced tenancy and user/member models touch every model and many tests |
| M2 | Medium | Catalog split and the PM removal across routes, navigation and settings. Central product and plan changes |
| M3 | Medium–high | New Central token rule with acting user, two APIs, failure handling. Security-sensitive |
| M4 | Medium | Specified in ADR-0012. Idempotency and attachment states |
| M5 | High | Offline storage with encryption, sync recovery, device testing. The largest new work |
| M6 | Low | Manifest and static-asset service worker |

**Risks, highest first:**

1. **Deploying `project-management` from this branch before M2.** Its M&E module is on by default
   (`Modules` switches are opt-out), so production M&E data would be created in the wrong database.
   Mitigation: no PM deploy that includes `routes/web/mel.php` until M2 removes it.
2. **The product-pair token with an acting user.** This is a new trust path between products. A
   flaw would expose project money or results across organizations. It needs its own review, and
   tests for every refusal.
3. **Offline data loss on the phone.** Mitigated by ADR-0012 §9, and only claimed after the §6.4
   device tests.
4. **Two copies during M1.** Both copies exist locally until M2 removes PM's. This is safe only
   because neither is deployed. It must not last past M2.
5. **Encryption on Expo SDK 57.** The `expo-sqlite` SQLCipher plugin options must be verified
   against SDK 57.
6. **Partner reports lose verified results** when M&E is down, or the organization has no M&E.
   Handled by recording that none were captured.
7. **A dirty working tree.** The branch carries another session's uncommitted files across many
   apps. M1 should start from a clean commit point.

## 15. Decisions (taken 2026-11-16, the recommended options)

1. **Path.** `{workspace}/mel`. Nothing is deployed, so no redirects.
2. **Local dev data.** Re-seed. The `mel` database starts empty, and no copy script is written.
   The dev rows in the `projects` database stay where they are until M2.
3. **Bundle.** Yes, a Projects + M&E bundle plan comes in M2. Its price is the owner's to set; the
   local seeder will mark it as a placeholder.
4. **Partner reports.** They keep verified results, read from M&E over the API in M3 (§9).
5. **Desktop.** The PWA is the MVP desktop (§7).

## 16. As built: M1 (2026-11-16, committed 5bfb4d4)

**What was built:**

- **Scaffold.** `mel-app/` from 05e6d31: composer, bootstrap, config, platform wiring,
  `RememberMember`, `WorkspaceMember`, layout and Vite.
- **Domain code.** The current `App\Mel`, `App\Collect`, `App\Http\Controllers\Mel`, pages,
  `resources/js/mel` and tests are copied from `project-management`, with the namespaces kept.
  It is a copy, not `git mv`: PM keeps its copy until M2 removes it.
- **What changed (§1.4):**
  - tenancy is platform-client's `BelongsToTenant` / `CurrentTenant`;
  - access is platform-client's `CurrentAccess`;
  - people pickers use `workspace_members`;
  - `LinkedProjects` is off (`enabled()` false; no figures, no new links, milestone pickers empty);
  - the relations to `Project` and `Milestone` are removed;
  - no foreign key to `projects`, `milestones` or `organizations`.
- **Provisioning.** `App\Platform\SetUpWorkspace` creates `mel_settings` and the starting
  dimensions. A repeat call refreshes the name, currency and time zone. `SetUpMel` covers first
  use without a provisioning call.
- **Catalog.** `mel-app/platform/catalog.json` is `mel` 1.0.0 (2.0.0 since M2A, §17), kind `standalone`: 25 permissions,
  9 roles and 3 separation-of-duties rules, taken from `projects` 3.3.0. None of them mixes with
  a Projects permission.
- **Routes.** `{workspace}/mel/*`, names `mel.*` (84 routes).
- **Navigation.** Dashboard, Plan, Monitoring, Data collection, Reports, Administration.
  Evaluations are not shown until built.

**Verified locally:**

- 85 tests pass on SQLite and on PostgreSQL (`mel_test`): the 76 ported ones, the restored
  provisioning and platform-access tests, and a new "M&E alone, revoked, restored" test.
- PostgreSQL schema: 44 tables, the 8 append-only and frozen-row triggers, and no foreign key to a
  Projects table. `migrate:fresh`, rollback and migrate pass.
- PHPStan and Pint are clean. tsc is clean, Vitest passes (38), the Vite build passes and
  `vp check` is clean.
- `project-management`'s M&E tests are unchanged (76 passed).

**Not verified:**

- Browser sign-in through Central. `mel` is not registered in the local Central, and there is no
  local proxy entry for `/mel`. Sign-in, the access refusal and provisioning are covered by
  platform-client's test fake only.

**Next: M2.**

- Central: `mel` application, products, plans, bundle and marketing.
- `projects` catalog 4.0.0.
- Remove M&E from `project-management`.
- CI job, nginx, php-fpm and the local proxy (from d0dc055).

## 17. As built: M2A (2026-11-16) — Central registration and independence

**Changed in the repository:**

- **Catalog.** `mel` **2.0.0**, the same content as M1's 1.0.0: 25 permissions, 9 roles and 3
  separation-of-duties rules. The local Central already held a published `mel` 1.0.0 from the
  `shared_module` era (d0dc055) with different content. Published versions are immutable
  (`CatalogConflict`), so the number moved.
- **Products** (`central-app/database/erp-products.json`):
  - Monitoring & Evaluation requires `mel` and lists `projects` as optional (it was `projects`
    required).
  - NGO ERP lists `mel` as optional.
- **Plans** (`LocalPlansSeeder`, local only):
  - `mel-standard` is M&E alone.
  - `projects-mel-bundle` is "Projects + M&E", composed explicitly of `projects` and `mel`. It has
    **no price**: a plan without one stays a draft, is not public and shows "price not approved".
    No price was invented.
- **Local proxy.** `{workspace}.tatook.localhost/mel/` goes to `127.0.0.1:9400`
  (`ops/local-dev/apache-dev.conf`).
- **CI.** A `mel` job in `.github/workflows/tests.yml`: `composer ci:check` on PostgreSQL 17,
  where the triggers run, then the suite again on SQLite.
- **Production templates (not deployed).**
  - `ops/nginx/products/mel.conf` and `ops/php-fpm/mel.conf`.
  - `docs/runbooks/mel-app.md`, with a pointer from `docs/runbooks/mel.md`. `mel.md` stays the
    production runbook until M2B.

**Local Central state (not in the repository):**

- **Application.** `mel`, "Tatook M&E", kind `standalone`. The kind was set by hand, because the
  importer never changes an existing application's kind.
- **Catalog.** 2.0.0 published, 1.0.0 retired.
- **Integration.** Endpoint `http://127.0.0.1:9400/mel/platform`; trial enabled.
- **Products and plans.** Monitoring & Evaluation is `pilot`. `mel-standard` is active at the
  seeded local price; the bundle is a draft.
- **Test organizations.** Four, with three users signed in through Central:

  | Organization | Entitlements |
  |---|---|
  | `m2a-mel` | `mel` |
  | `m2a-pm` | `projects` |
  | `m2a-both` | `projects`, `mel` |
  | `m2a-none` | none |

  The users are the owner, a `mel_officer`, and a member without an M&E role.

**Verified by real HTTP.** This was a local run through Apache, against the real Central, with the
OIDC/PKCE flow and the queue worker; platform-client's fake was not used. The scripts are in the
session scratchpad.

- **SSO.**
  - Sign-in at `{ws}/mel/` goes through Central's login, authorize and callback.
  - Switching to a second workspace reuses the Central session without a password.
  - Logout ends the M&E session. The next visit signs in silently while Central's session lives,
    as in Projects. A browser without a Central session lands on Central's login.
- **Entitlements, the four scenarios:**

  | Scenario | `/mel` | `/projects` |
  |---|---|---|
  | M&E only | 200 | 404 |
  | PM only | 404 (home and direct URLs) | 200 |
  | Both | 200 | 200 |
  | Neither | 404 | 404 |

  - A workspace is not visible to an application it is not entitled to (platform-client
    `ResolveTenant`), hence 404 rather than 403.
  - A programme id from `m2a-both` opened in `m2a-mel` is 404.
- **RBAC.**
  - The officer gets 17 `mel.*` permissions and nothing else.
  - The officer is refused settings (403) and creating a programme (POST, 403).
  - A member without a role gets 403.
  - A role granted in Central (`mel_administrator`) reached an **open** session within seconds:
    17 permissions became 25, and settings went from 403 to 200.
- **Revocation.**
  - Revoking `mel` for `m2a-both` makes an open session's next request 403.
  - A new sign-in is refused at Central with `invalid_client`, because `TenantRedirectUris`
    removes the workspace's callback. Central shows its raw JSON error.
  - Projects in the same workspace stays 200.
  - Re-entitling restores 200 with the programme intact.
- **M&E-only workflows**, in `m2a-mel` and again in `m2a-both`, all over HTTP:
  1. A programme, two units and quarterly periods.
  2. A goal, an outcome and an output, and the framework page.
  3. An indicator, activated, with a baseline and four targets.
  4. The officer records and submits a value with a PDF uploaded as evidence.
  5. The owner downloads the evidence, reviews, verifies and publishes. The submitter could not
     review their own result: separation of duties works as designed.
  6. Six reports render for the programme.
  7. A Collect form is created, its draft saved (as JSON, the way the page sends it), published,
     and assigned to the officer.
- **Database independence.**
  - `mel_app` has 44 tables: the `mel_*` and `collect_*` tables, plus Laravel's, platform-client's
    and `workspace_members`.
  - It has 163 foreign keys, all internal. None points to `projects`, `milestones` or
    `organizations`.
  - There are no foreign servers, and the only extension is `plpgsql`.
  - No code in `mel-app` opens another connection or calls Projects or Finance.
  - All 12 migrations roll back and re-apply on PostgreSQL.
  - It is its own database (`mel_app` on :5432; Projects is on :5433), backed up and restored
    on its own.
- **Regression.**
  - `project-management`: 497 passed, 7 skipped.
  - Projects ⇄ Finance E2E: 17 of 17.
  - Central, in Products, Entitlements, Marketing, SharedModules, Billing, Applications and
    Identity: 319 of 320. The one failure is `ProductSalesTest`'s public landing assertion. It
    comes from another session's uncommitted public-site work and is unrelated.
  - `mel-app`: 85 passed on SQLite and on PostgreSQL. Pint and PHPStan are clean, tsc is clean,
    Vitest passes (38), the build passes, and `vp check` has 0 errors and 1 warning.

**Found, not fixed in M2A:**

- **Data Collection is partial.**
  - Built: form definitions and their checker; versions (publish, close, archive, restore,
    duplicate); assignments; the rules engine (PHP and TS); the builder and renderer modules.
  - Missing pages: `mel/collect/forms/build` (the designer), `mel/collect/forms/version` and
    `mel/collect/assignments/index` exist neither in Projects nor in `mel-app`. Those routes
    answer 500.
  - Missing behaviour: nothing writes `collect_submissions`. There is no submission API, no review
    of collected submissions, no attachment upload, no export generation and no offline sync.
    The tables and models exist with nothing behind them.
  - No feature tests cover the Collect HTTP surface.
- **Production Central.** `mel` may already exist there as a `shared_module` from catalog 1.0.0.
  It must be set to `standalone` once, by staff (see the runbook). While it is a shared module,
  the marketing catalog finds no application of M&E's own.
- **Marketing.** The public `monitoring-evaluation` entry in
  `resources/content/marketing-catalog.json` still has registry code `projects`, so a trial started
  from that page is a Projects trial. That file holds another session's uncommitted edits, so it
  was not touched.
- **Pricing.** `mel-standard`'s local price is a seed value, and the bundle has none. Both need
  approval before anything is public.

## 18. M2B plan — remove M&E from Project Management (built: §19)

**What leaves `project-management`:**

- **Routes.** `routes/web/mel.php` (78 of 422 routes) and its include in `routes/web.php`, behind
  `RequireModule:mel` and `SetUpMel`.
- **Code.**
  - `app/Mel` (43 files), `app/Collect` (14) and `app/Http/Controllers/Mel` (15).
  - `resources/js/pages/mel` (21) and `resources/js/mel` (10).
  - `tests/Feature/Mel` (3) and `tests/Unit/Mel` (2).
  - `components/charts.tsx`, if nothing else uses it.
  - The generated `@/routes/mel` and `@/actions/...Mel`.
- **Module and navigation.** `Modules::MEL` and its settings toggle, and the M&E block in
  `resources/js/lib/navigation.ts`.
- **Permissions.**
  - `platform/catalog.json` goes from 3.3.0 to **4.0.0**, without the 25 `mel.*` permissions and
    the 9 `mel_*` roles: 67 permissions become 42, and 21 roles become 12.
  - On Central, `platform:catalog-reassign projects --dry-run` runs first. Holders of `mel_*`
    roles in Projects lose them there, so each needs the same role in `mel` before the deploy.
    That mapping is a staff step to approve.
- **Tables.** The five migrations `2026_11_15_100000`–`100400` stay in history. A new migration
  drops `mel_*` and `collect_*` only once the data question below is decided. Until then the
  tables stay, unused.

**What stays and changes in Projects:**

- **Partner reports.**
  - `Report` type `mel` ("M&E performance report") and `ReportContent::mel()` read
    `ProgrammeUnit` and `VerifiedResult` directly.
  - M2B removes that section, so partner reports have no M&E section and the `mel` type is hidden
    for new reports. M3 replaces the section with the integration read (§9). Existing reports keep
    their stored snapshot.
  - The current read includes verified but **unpublished** results, with a `published` flag. M3
    must decide whether a partner sees those.
- **Collaboration.** `Capability::RESULTS_VIEW` and `EVIDENCE_SUBMIT` imply `mel.*` permissions,
  and `Responsibility` has an "M&E officer". They point at an application Projects no longer
  serves. M2B hides them (they are already `effective: false`), or M3 maps them to the
  cross-application grant.
- **Portal.** `pages/portal/collaboration/report.tsx` renders `system.mel`. It shows nothing once
  the content is empty.

**Data:**

- There is no production data to move: M&E in Projects has never been deployed (§1.2).
- If it is deployed before M2B, the existing `mel_*` and `collect_*` rows need an export and an
  import into `mel`, per organization, with their own approval (§4).
- Test M&E rows in local `projects` databases are not migrated.

**Sequence:**

1. Back up the Projects database.
2. In PM, remove the routes, navigation, module, pages and code. Keep the tables. Publish catalog
   4.0.0.
3. Take `ReportContent::mel()` out of partner reports. Add tests that an existing `mel` report
   renders without the section.
4. Run the PM suite and the Projects ⇄ Finance E2E (17 scenarios).
5. Rerun the M2A HTTP scenarios: Projects in the PM-only and both workspaces, and M&E in the
   M&E-only and both workspaces.
6. On Central, run the catalog reassign dry run, map the roles, then run the reassign.
7. Deploy, with its own approval: `mel-app` first (per the runbook), then PM without M&E.
8. Later, add a migration that drops the unused tables.

**Rollback:**

- **Before the deploy.** Revert the M2B commits. They only remove code, and the tables were kept.
- **After the deploy.** Redeploy the previous PM release. Its catalog 3.3.0 is still published,
  and a `catalog-reassign` back restores the roles.
- **`mel-app`.** It is unaffected either way: it has its own database, and nothing in it points
  at Projects.

## 19. As built: M2B (2026-11-16, local, not deployed) — M&E removed from Project Management

**Removed from `project-management`:**

- **Routes.** `routes/web/mel.php` and its group in `routes/web.php`, with `RequireModule:mel` and
  `SetUpMel`.
- **Code.**
  - `app/Mel`, `app/Collect`, `app/Http/Controllers/Mel` and `app/Http/Middleware/SetUpMel.php`.
  - `resources/js/pages/mel` and `resources/js/mel`.
  - `tests/Feature/Mel` and `tests/Unit/Mel`. `tests/Unit` keeps a `.gitkeep`, because Pest and
    phpunit expect the directory.
  - 110 files in all.
- **Module.** `Modules::MEL` and its Settings → Modules toggle. An organization that stored `mel`
  in `disabled_modules` is unaffected: unknown keys are ignored.
- **Navigation.** The "Monitoring & evaluation" group and the two M&E administration items, with
  their route and icon imports.
- **Catalog.** `projects` **4.0.0** (version line and deletions only):
  - 67 permissions become 42, 21 roles become 12, and 6 separation-of-duties rules become 3;
  - no non-M&E role held a `mel.*` permission.
- **Test helpers.** `mel()`, `setUpProgramme()`, `addIndicator()` and `enterResult()` are gone from
  `tests/Pest.php`.
- **Tests.**
  - `ProjectAccessTest` loses its three linked-project tests. Linked-project figures come back with
    the integration (M3) and must be re-specified there.
  - `PartnerReportsTest` gains "records no M&E results with a submission now that M&E is its own
    application".

**Changed, not removed (differences from §18):**

- **Partner reports.**
  - `ReportContent::snapshot()` keeps the key `mel` as an empty list, so the shape stored in frozen
    versions is unchanged. The portal still renders M&E results captured in older snapshots.
  - The direct read of `ProgrammeUnit` / `VerifiedResult` is gone.
  - The report category "M&E performance report" (`mel`) is **kept**: it is a report a partner
    writes, not data read from M&E. §18 had planned to hide it.
- **Collaboration labels.**
  - `Capability::RESULTS_VIEW` and `EVIDENCE_SUBMIT` are kept. They were already
    `effective: false`, so they are never offered or enforced. M3 maps them to the
    cross-application grant.
  - `Responsibility` "M&E officer" is kept: it is a person's job in a partnership.
- **Tables.** The five migrations stay, and the `mel_*` and `collect_*` tables stay, unused.
  `ParticipationInventory` still counts `mel_partners` (the participation-model inventory), so the
  later drop migration must update it.
- **Shared components.** `components/charts.tsx` is kept: the portal home and beneficiary pages use
  it.

**Runbooks:**

- `deploy.md`: M&E is `mel-app`; deploy it before this Projects release.
- `mel.md`: now operations of the standalone application. It records that linked projects are off
  until M3, and that "cannot open M&E" means entitlement (404) or role (403).
- `mel-app.md`: updated for after M2B.

**Verified locally:**

- **Backup.** Projects database dump before the change:
  `D:/srv/backups/projects-before-m2b-20261116-1555.dump`, 125 tables of data.
- **PM suite.** 420 passed, 7 skipped. It was 497 with M&E: the M&E tests, the three
  linked-project tests and the new report test account for the difference.
- **PM static checks.**
  - PHPStan clean, Pint clean, tsc clean, build passes.
  - Vitest has no test files left (PM's only frontend tests were Collect's) and exits 0.
  - `vp check` reports formatting in 34 files, none touched here: `docs/marketing/*`, plus
    `docs/phase-1-audit.md` and `vite.config.ts`, which are another session's uncommitted edits.
    It will fail PM's CI `npm run check` until those are formatted.
- **Real HTTP** (Apache, real Central, PM and mel-app), 15/15:
  - Projects opens in `m2a-pm` and `m2a-both`.
  - Its dashboard modules are documents, grants, procurement and accounting; Settings → Modules
    has no M&E toggle.
  - `/projects/mel`, `/projects/mel/programmes`, `/projects/mel/collect/forms` and
    `/projects/mel/reports` are 404 in both workspaces.
  - M&E in `m2a-both` still opens its programmes.
- **M2A scenarios rerun.**
  - Isolation, SSO and RBAC: 20/20.
  - M&E-only workflow: 22/24. The two failures are the missing Collect pages (§17).
- **Projects ⇄ Finance E2E: not rerun after M2B.** The run was stopped by the host for low memory. M2B
  changes no Finance-integration code (the financial part of `ReportContent` is untouched); the
  last run, at e955f49 before M2B, was 17/17. Rerun `php ops/e2e/finance-projects/run.php` before
  any deploy.
- **Local Central.** `projects` 4.0.0 imported.
  - `platform:catalog-reassign projects --dry-run`: 2 assignments to move, 0 left. No local
    organization held a `mel_*` role under Projects.
  - The reassign was then applied, locally only.

**Production, when approved (not done):**

1. Back up the Projects database.
2. Register and deploy `mel-app` (`docs/runbooks/mel-app.md`), including the one-time kind change
   to `standalone`.
3. Deploy Projects with catalog 4.0.0.
4. On Central, run `platform:catalog-reassign projects --dry-run`.
   - If anyone holds a `mel_*` role under Projects there, they need the same role in `mel` first,
     then `platform:catalog-retire-role` for the Projects role.
   - Then run the reassign.
5. Rollback: redeploy the previous Projects release. Catalog 3.3.0 is still published; reassign
   back to it.

## 20. As built: M3 (2026-11-16, local, not deployed) — Projects and M&E integration

M3 connects the two applications without either one reading the other's database. Each keeps its
own data. They read each other over HTTP, with short-lived tokens signed by Central. Both sides are
optional: a deployment that leaves the integration URLs empty behaves as before.

### 20.1 Architecture

```
mel-app ──GET──▶ project-management  /projects/integration/v1/…   (projects, milestones)
project-management ──GET──▶ mel-app   /mel/integration/v1/…        (verified results)
      ▲                      ▲
      └── token from Central POST /api/v1/integration-tokens (scope integration.read)
```

- **Reads only.** `IntegrationClient` sends GET requests and nothing else. Neither app writes to the
  other.
- **Configuration.**
  - `PLATFORM_PROJECTS_INTEGRATION_URL` is set in mel-app; `PLATFORM_MEL_INTEGRATION_URL` is set in
    project-management.
  - When the URL is empty, the integration is off ("not configured").
  - Both `phpunit.xml` files pin these to empty. A developer's `.env` can therefore never send a
    test to a running server.

### 20.2 Token authorization (Central)

- **Pairs.** `EventTokenIssuer::issueIntegration` allows only `projects⇄mel` (`PRODUCT_PAIRS`).
- **Token.** RS256, valid for 300 s.
- **Claims.**
  - `iss`: Central.
  - `aud`: the receiving app.
  - `src`: the requesting app.
  - `org`: the organization.
  - `scope`: `integration.read`.
  - `act` (optional): the member on whose behalf the read is made.
- **Refusals.**

  | Refusal               | HTTP | Meaning                                           |
  | --------------------- | ---- | ------------------------------------------------- |
  | `unknown_partner`     | 422  | Not an allowed pair                               |
  | `organization_inactive` | 422 | The organization is not active                  |
  | `not_entitled`        | 404  | The organization lacks one of the two apps        |
  | `entitlement_expired` | 422  | A subscription ran out                            |
  | `not_member`          | 403  | `act` is not a member of the organization         |

- **Expiry.** `entitlement_expired` is told apart from `not_entitled` by a lapsed, unrevoked
  whole-application entitlement.
- **Client.** `IntegrationClient` maps `entitlement_expired` to `NotEntitled` with that error code.
  PM records it as `subscription_expired`.

### 20.3 Client and API contracts

**`IntegrationClient` (platform-client).**

- Timeout of 3 s (2 s to connect).
- One retry, only on 502, 503 or 504 or a dropped connection. A timeout is never retried.
- Sends `X-Correlation-Id` and logs the source, organization, acting user, path, status and
  duration.
- Results are typed (`IntegrationStatus`): Ok, NotConfigured, NotEntitled, Denied, NotFound,
  Unavailable, Timeout, Invalid.

**Receiving middleware.** `platform.integration:{source}` (`RequireIntegrationToken`):

- verifies the signature, issuer, audience, expiry and scope;
- requires the expected source;
- answers 401 `invalid_token` or 403 `source_not_allowed`.

**Projects API** (`/projects/integration/v1`, source `mel`). Behind `EnterIntegration`:

- Requires `act` (403 `acting_user_required`).
- The organization comes from the token only; request parameters are ignored. Errors: 404
  `not_provisioned`, 403 `organization_inactive`.
- The member's effective access comes from Central. Without `projects.view` the answer is 403
  `forbidden`.
- The read then runs as that member: organization scope, team-only visibility
  (`ProjectVisibilityScope`), and money figures only with `projects.finance.view`.
- Endpoints:
  - `GET projects`: lists the projects the member can see (code, name, status), up to 500.
  - `GET projects?ids[]=…[&with=milestones]`: 1–100 uuids, with figures. Projects the member cannot
    see are listed under `missing`.
  - `GET projects/{id}`: 404 `unknown_project` when the member cannot see it.
  - `GET milestones?ids[]=…` or `?project_ids[]=…`: only milestones of projects the member can see.

**M&E API** (`/mel/integration/v1`, source `projects`).

- `EnterIntegration` checks the organization with Central and enters it. 404 `not_provisioned`
  when M&E was never set up there.
- `GET projects/{id}/verified-results?from=Y-m-d&to=Y-m-d`:
  - the period is required (422 otherwise);
  - returns `linked`, `units`, `shared`, `truncated` (more than 200 rows) and `data`.

### 20.4 Result eligibility (decided in M&E, `ProjectsIntegrationController`)

A result is sent only if all of these hold:

1. **Organization.** It belongs to the organization the token names.
2. **Project.** Its reporting unit is linked to this project.
3. **Sharing.** That unit is marked **"Report published results to the project owner"**
   (`mel_programme_units.shares_with_project_reports`):
   - It is off by default, including for every link that already exists.
   - An M&E manager turns it on per unit (`mel.projects.link`), and each change is audited
     (`unit.project_reporting_on|off`).
   - Unlinking a unit, or moving it to another project, turns it off.
4. **Period.** Its reporting period overlaps the report's period, with both ends included.
5. **Status.** It is verified and published, and still standing: a superseded value is never sent,
   so there is one value per indicator, unit and period.
6. **Indicator.** The indicator is active or retired, never a draft.

**Fields sent:**

- indicator id, version id and code;
- unit;
- period and its dates;
- measure and unit label;
- value, numerator and denominator;
- verification and publication times;
- for evidence marked "share with donors": the file name, type and size only.

**Never sent:**

- files or paths;
- narratives;
- disaggregation cells;
- people's names;
- Data Collection submissions.

Values are never added up, so measurements that cannot be combined are not combined.

### 20.5 Snapshots (project-management)

- **Before the transaction.** `Reports::submit` reads M&E (`MelResults::forReport`) **before** the
  submission transaction, so a slow M&E never holds a database lock.
- **Frozen with the version.** The answer is frozen into the submitted version:
  - `content.system.mel` holds the results with their source ids and times;
  - `content.system.melSource` holds `{application, status, retrievedAt, correlationId, error,
    truncated}`. The status is kept apart from the values.
- **Statuses:**
  - `captured`;
  - `no_results` (the units are shared, nothing is published for the period);
  - `not_shared` (the units are linked, none is set to report);
  - `unlinked`;
  - `no_period` (M&E is not asked);
  - `off`;
  - `not_entitled`;
  - `subscription_expired`;
  - `not_set_up`;
  - `denied`;
  - `unavailable`;
  - `timeout`;
  - `error`.

  "No results" is recorded only when M&E said so.
- **Immutability.**
  - A submitted version is never rewritten. A second submit is refused ("cannot be changed"), and
    no version is added.
  - Resubmitting after changes are requested makes a **new** version with a fresh read.
  - A later correction or unsharing in M&E changes only future reads.
- **Never blocks.** The submission never fails because of M&E.

### 20.6 Disclosure policy (the security review's decision)

> **Superseded by §24 (M7):** results are now released per unit and per result to the submitting
> partner, its reviewer and donors on publication. What follows is the M3 rule, kept as history.

**Problem.** As first built, the results went into the report content and were visible to every
party that could see the report: the submitting partner, a lead reviewing a sub-partner, and
donors once the report was published.

**Least privilege now.**

1. **What leaves M&E.** M&E sends only what §20.4 allows. The decision to share is made per unit by
   M&E, not by Projects.
2. **Who makes the read.** It is the owner organization's service read, with no acting member. A
   partner submitting a report gains no access to M&E, and Projects never reads M&E as a partner.
3. **Who sees the results.** The results are recorded with the version but shown **only to members
   of the project's owner organization** who have `projects.view` (`Reports::seesMelResults`).
   - `ReportController::content` removes `system.mel` and `system.melSource` on the server for
     everyone else: implementers, leads, sub-partners and donors.
   - This holds at every status, including after publication.
   - The page then shows nothing about M&E to them.

**Alternatives needing a business decision. Not built, and external access was not expanded:**

- **B. Share with the reporting chain.** The submitter, its reviewer and the project owner see the
  results. Leads would see results about their sub-partners' units.
- **C. Share with donors on publication.** Published reports carry the results to donor
  participants holding `donor_reports.view`. This needs a per-unit "donor-visible" decision in M&E,
  separate from §20.4's sharing.

Either one needs a decision from the business. It would be one change to `Reports::seesMelResults`,
plus tests.

### 20.7 Linking (mel-app)

- **Checked live.** A unit links to a project, and a result to a milestone, only after Projects
  confirms it **as the acting member**. Errors are refused with a reason in plain words:
  - the project does not exist or the member cannot see it;
  - the organization does not have both apps;
  - Projects is down.
- **One unit per project.** A project can be linked to only one unit per programme.
- **Idempotent.** Saving an unchanged link records nothing.
- **Shown live.** Linked projects and milestones are read live as the viewing member, never copied
  into M&E.
  - When Projects cannot answer, the page says why and keeps the stored ids.
  - A milestone that Projects no longer shows stays as "Milestone kept from before (not shown by
    Projects now)", with no live data. The reference is kept without granting access.
- **Archived, completed or cancelled projects.** They still answer through the API with their
  status. An archived M&E unit keeps its published results.

### 20.8 Legacy

`mel_partners` and the other legacy M&E tables stay in project-management (M2B, §19). Nothing
active reads them:

- `ParticipationInventory` (`projects:participation-inventory`, an operator report) counts
  `mel_partners` only when the table exists. It is labelled "legacy, not the live M&E database".
- The capability labels `RESULTS_VIEW` and `EVIDENCE_SUBMIT` remain `effective: false`.

**Retiring the tables** needs, in order:

1. confirm on production that no rows are needed, or export them to M&E's own tables;
2. drop the inventory line;
3. add a migration that drops them, in a later release.

This is not done in M3.

### 20.9 Verification (2026-11-16, local only)

**Automated suites:**

- project-management: 451 passed, 7 skipped. This includes:
  - `PartnerReportMelTest` (18): disclosure in both the donor → implementer chain and the
    donor → lead → sub-partner chain, duplicate submission, no period;
  - `MelIntegrationApiTest` (13);
  - `PartnerReportsTest`.
- mel-app: 103 passed on SQLite and 103 passed on PostgreSQL. This includes
  `VerifiedResultsApiTest` (9) and `ProjectLinkTest` (9).
- Central `tests/Feature/SharedModules`: 29 passed, including `IntegrationTokenTest` (5).
- platform-client: 146 passed, including `IntegrationTest` (11).

**Static checks and builds:**

- PHPStan is clean in project-management and mel-app.
- platform-client has 3 PHPStan errors, all in files that predate M3.
- Pint passes, and `tsc` is clean in both apps.
- Production builds succeed in both apps.
- `vp check` reports formatting problems only in other sessions' files (`docs/marketing/*`,
  `project-management/vite.config.ts`) and one lint warning in `mel-app/…/collect/rules.ts`. None
  are M3 files.

**Real HTTP.** These checks ran between separately running servers with Central-issued tokens:
Central on :8101, PM on :9300, M&E on :9400. They were run as scripts, **not in a browser**.

1. **Project lookup.** The member's project figures come back.
2. **Milestone lookup.** Milestones come back by id.
3. **Link.** The link is saved and the figures and milestones appear on the M&E page.
4. **Invalid project id.** Refused with "That project is not in Projects, or you cannot see it
   there."
5. **Team-only project.**
   - An officer gets 404 `unknown_project`, and the milestone list comes back empty.
   - The owner gets 200.
   - A member without `projects.view` gets 403 `forbidden`.
   - A token without a member gets 403.
6. **Another tenant's project.** 404, and the project is listed under `missing` in a list call.
7. **Verified results.**
   - Q1 returns one result (value 1200, evidence name only).
   - Q3 returns nothing.
   - A request with no period gets 422.
8. **Disclosure.**
   - A linked but unshared unit returns `shared: 0` with no data.
   - The partner's report page carries `mel: []` and no `melSource`; the owner's page shows the
     result.
9. **Submission.**
   - Version 1 is frozen with `captured`, result id `01a1e446-5416…`, the publication time and the
     correlation id.
   - A second submit is refused.
   - Turning sharing off afterwards makes live reads `not_shared`, and the frozen value stays 1200.
10. **M&E stopped.** The submission succeeds and is frozen `unavailable` / `unreachable`.
11. **Expired subscription.** PM records `subscription_expired`, and Central refuses M&E's token with
    `entitlement_expired`. The entitlement was then restored exactly.
12. **Missing subscription.** An organization without M&E is recorded as `not_entitled`.
13. **Bad tokens.**
    - A garbage token or a tampered `org` claim gets 401 `invalid_token` from both apps.
    - A real token used 345 s after issue (300 s lifetime + 30 s allowed clock skew) gets 401
      `invalid_token`. At 320 s it was still accepted, as the skew allowance intends.
14. **Retry and timeout** (against a counting stub on :9400).
    - A 503 is retried exactly once (2 hits), then recorded `unavailable` / `http_503`.
    - A 6 s response gets 1 hit and is recorded `timeout`.
    - One real submission also timed out: the local M&E server took about 4 s with little free
      memory. It was frozen `timeout` with no results.

**Finance E2E:** `php ops/e2e/finance-projects/run.php`, run with the M3 servers stopped: 17 of
17 passed. Finance was not changed.

**Not verified:**

- browser rendering of the new panels and the sharing checkbox;
- staging and production;
- a real lead / sub-partner chain over HTTP (covered only in feature tests);
- PM's integration timeout under production PHP-FPM.

### 20.10 Security risks and deviations

- **Service read without a member.** The PM → M&E read for reports has no `act`. It is limited by
  M&E's eligibility rules (§20.4) and Projects' audience rule (§20.6). Anyone who can submit a
  report can trigger a read for the owner organization, but they never see its answer.
- **Short timeout.** The 3 s timeout was exceeded locally under memory pressure. Production should
  watch the `Integration request` duration logs, and the timeout should be revisited if `timeout`
  snapshots appear.
- **Deviations from the M3 brief.**
  - Results were owner-only in M3. The partner and donor audiences were built in M7 (§24).
  - M&E sends nothing until a manager turns sharing on.
  - A report period is now required for M&E results.

## 21. As built: M4 (2026-11-16, committed 575ccd1, local, not deployed) — Data Collection backend

**Intake (`App\Collect\Submissions`).**

- `POST {ws}/mel/api/v1/collect/submissions` takes a whole submission with an id the phone made
  (UUIDv7).
  - The same id, author and content (canonical hash, ignoring `app_version`) is a replay: 200 with
    `replayed: true`.
  - The same id with other content, or another person's, is 409 `id_conflict`.
- Refusals carry a machine code (`App\Collect\Refused`):
  - 404 `form_not_found`: unknown version, or no live assignment;
  - 403 `assignment_revoked` and `assignment_closed`;
  - 409 `form_archived` and `version_closed`;
  - 422 `consent_required`;
  - 422 `invalid` with per-question rule errors. The server runs the same rules engine, against the
    shared conformance cases.
- Photos.
  - The submission lists its photo manifest (question, sha256, size, mime). It must match the
    photo answers, which are the sha256.
  - Files arrive one by one with `PUT …/attachments/{question}` (raw body or multipart). Each is
    checked against the manifest and sniffed as JPEG, PNG or WebP.
  - A resent identical file is accepted; a different one is 409 `file_conflict`.
  - The submission stays `receiving` until the last photo arrives, then becomes `submitted`.
    `GET …/submissions/{id}` lists what is `missing`.
- `GET …/submissions?since&after&limit` is an incremental feed (cursor `updated_at` + id) of the
  collector's own submissions, so the phone learns about reviews.
- Corrections (`POST …/corrections`) need the base revision. A stale base is 409
  `revision_conflict`; a lost reply's resend is recognised. Photos cannot change in a correction.
- Every submission keeps its revisions (`collect_submission_revisions`). The current answers are
  the last revision's.

**Review (`App\Collect\Review`, web).**

- The queue, a submission page and the actions.
  - The actions are start, accept, reject (with a reason), request correction (comment plus notes
    per question) and reopen (with a reason).
  - Separation of duties: the reviewer must not be the author when
    `collect_reviewer_must_differ` is set.
- Every decision is a `collect_review_decisions` row plus an audit event.
- Personal answers, photos and locations are hidden on the server without
  `mel.collect.personal_data`. Viewing a photo needs that permission, is audited, and is served
  `no-store` with `default-src 'none'`.
- The web form designer (outline, properties, choices, rules, live preview, autosave, publish),
  versions, assignments and a summary of accepted data are in the same release.

**Exports (`App\Collect\Exports`).**

- CSV (BOM, formula-escaped) or XLSX, synchronous, at most 50,000 rows.
- Current or original answers; one column per question per type version.
- Personal columns only with `mel.collect.personal_data`, otherwise a note says they were left out.
  Photos are never exported.
- Only the requester can download (404 for others), and only while the form is still in their
  reach. A download is refused with 410 after 7 days, and with 403 once the personal-data
  permission is withdrawn.
- Requests and downloads are audited.

**Database (additive migration `2026_11_17_090000`).**

- `collect_submissions.source` and `collect_exports.answers`, plus a sync index.
- On PostgreSQL:
  - CHECK constraints on every status and on received ≤ expected photos;
  - trigger `collect_revisions_not_decided`, which refuses a revision on an accepted or rejected
    submission.

**Decisions.**

- No server-side drafts: drafts live on the phone (ADR-0012 §14, no web data entry in the MVP).
- Exports are synchronous.
- Phone-only enumerators become workspace members on their first API call (`RememberMember` on the
  API), so they can be assigned.

**Not built.** Indicator mapping and calculation runs from collected data (ADR-0012 §11).

## 22. As built: M5 (2026-11-16, committed 34ddcab, local) — the field app `mel-mobile-app`

- **Stack.** Expo SDK 57 and expo-router, scaffolded from `petrol-mobile-app`'s sign-in and API
  layer. Android first.
- **Sign-in.** Central PKCE or Google through Central; tokens are kept in the Android Keystore. Then
  `POST {ws}/mel/api/session` and `GET api/v1/me` (new). The person chooses the organization when
  they belong to more than one.
- **Storage.**
  - One SQLCipher database (`expo-sqlite` with `useSQLCipher`). Its key is 32 random bytes in the
    secure store.
  - Records are scoped `workspace|user`, so another person on the same phone sees nothing.
  - Rows that cannot be read are moved to `quarantine`, never dropped.
  - `allowBackup` is false. Storage, audio and gallery permissions are blocked.
- **Forms.**
  - The assignment kit, and immutable versions downloaded once per hash.
  - Rendered from the definition with `src/collect/rules.ts` and `definition.ts`, byte-identical
    copies of mel-app's (`npm run rules:check`), tested against `contracts/collect/rules-cases.json`.
  - A consent gate: declining deletes the draft.
  - Drafts autosave about once a second and on leaving.
  - Photos are re-encoded to JPEG under the workspace limit, which drops EXIF. They are stored
    privately and deleted once the server has them.
  - GPS records its accuracy, and a fix worse than 50 m gets a warning.
- **Sync (`src/collect/sync.ts`, pure TypeScript).**
  - A lost reply is settled by resending the same id.
  - "Synced" only once the server reports the submission past `receiving`.
  - Photo uploads resume from the server's `missing` list.
  - Offline, timeouts, 429 and 5xx back off with jitter from 30 s to 30 min.
  - 401 waits for sign-in.
  - 403, 404, 409, 410, 422 and 426 become *Action required* and are never retried alone.
  - Correction requests are pulled from the feed and sent against the reviewer's base revision.
  - Sync runs on reconnect, on returning to the foreground and every minute.
- **Sign-out.** Unsent records are never deleted. With unsent work, signing out needs a typed
  confirmation.
- **Tests and checks.**
  - Jest, 45 tests: the protocol against a fake server that drops requests or loses replies; a
    restart mid-upload; a week offline; refusals; corrections; quarantine; UUIDv7; and the
    conformance cases.
  - `tsc` and ESLint are clean, and the Android JS bundle exports.
- **Not verified.**
  - Not run on a device or emulator, so SQLCipher, camera, location and files are untested at
    runtime.
  - No EAS project yet.
  - `PLATFORM_MOBILE_CLIENT_ID` is not registered. Until it is, the field API answers 404 (by
    design, `RequirePlatformToken`), as seen over HTTP locally.
  - The icon and splash are placeholders.

## 23. As built: M6 (2026-11-16, committed 38a5b69, local) — desktop PWA

- **Installable.** `public/mel/manifest.webmanifest` (scope `/mel/`, standalone), icons (any and
  maskable) cut from the Tatook mark, and the theme colour. The manifest is linked from every page.
- **Service worker (`public/mel/sw.js`).**
  - It precaches only a data-free `offline.html` (no script) and the icons.
  - At runtime it caches only content-hashed `/mel/build/assets/*` files without a query, and never
    one marked `no-store` or `private`.
  - Pages, JSON, the API, the integration API, exports, photos and Vite's manifest always go to the
    network and are never stored.
  - When a page fails to load offline, it serves the offline page.
  - Old `mel-*` caches are dropped when a new version activates.
- **Page.** An offline banner, and a "new version ready — reload" prompt (`skip-waiting`, then
  reload on `controllerchange`). It checks for updates hourly and is off in development.
- **No Electron or Tauri.** Nothing needs native desktop access. Offline data entry is the phone's.
- **Tests.**
  - Vitest runs the real `sw.js` in a sandbox (what may be cached, the precache list, data requests
    left alone).
  - Pest covers the manifest, icon sizes, the offline page and the manifest link.
  - Over HTTP through Apache, the manifest, worker, offline page and icons are served with the
    right types.
- **Not verified:** installing it in a browser, and the update prompt in a browser.

## 24. As built: M7 (2026-11-16, committed 96b209b, local) — releasing M&E results to project parties

This replaces §20.6's owner-only rule. The M7 brief is the business decision §20.6 waited for:
alternatives B and C, made per unit and per result.

**Separate decisions, each by its own owner:**

1. **Verification** of a result in M&E.
2. **Publication** of a result in M&E (§20.4: only published results leave).
3. **Release** to project parties.
   - This is a per-unit M&E decision, audited `unit.project_audience_changed`, made by those with
     `mel.projects.link`.
   - The audience is `owner` (the default, and what every unit had), `partner`, or `donors`.
   - `partner` and `donors` name a partner organization, checked in Projects as active on the
     project (`GET projects/integration/v1/projects/{id}/participants`, new).
   - The release is withdrawn when reporting to the project is switched off or the link changes.
4. **Publication of the report** in Projects.

**On the wire.** Each verified result carries `audience` and `partnerOrganizationId`. Projects
freezes both with the version (`MelResults::result`). An unknown or absent audience is stored as
`owner`.

**Who sees a recorded result (`Reports::visibleMelResults`, server side, default deny):**

| Party | Sees |
|---|---|
| Project owner members with `projects.view` | all results |
| The partner that submitted the report (prepare permission + `reports.submit`) | results with audience `partner` or `donors` whose partner is that organization |
| The party reviewing that report, e.g. the lead for a sub-partner (review permission + `reports.review`) | the same results: the submitter's released scope, never other units' |
| A sub-partner | only results released to itself, on its own reports |
| Donors (`donor_reports.view`) | only `donors` results, only once the report is published |
| Anyone else, and legacy snapshots for non-owners | nothing |

`melSource`, the record of how the read went, stays with the owner.

**History and revocation.**

- A submitted version keeps the audience M&E gave at submission.
  - Withdrawing or narrowing a release in M&E applies from the next submission and does not rewrite
    a recorded version. This is the same rule as values (§20.5).
  - If a released result must be withdrawn from a submitted report, the owner asks for changes and
    the partner resubmits; the new version records the new release.
- Ending a participation, or withdrawing its report capabilities, removes access to the report
  through Projects' existing rules, and the results go with it. This was tested with a revoked
  participation.

**Never sent:** raw Data Collection responses, personal data, narratives, disaggregation cells or
evidence files. Evidence goes by name only.

**Database.**

- Additive migration `2026_11_17_120000`: three columns on `mel_programme_units`.
- On PostgreSQL, CHECK constraints: the audience is one of the three values, and `partner` requires
  a partner organization.

**Tests.**

- PM `PartnerReportMelTest` (+3):
  - only the released result reaches the submitting partner;
  - sub-partner, lead and donor before and after publication, and a non-party;
  - a release withdrawn after submission, the next version, and a revoked participation.
- PM `MelIntegrationApiTest` (+1): participants.
- M&E `ProjectLinkTest` (+4) and `VerifiedResultsApiTest` (+2, one PostgreSQL only).

**Real HTTP (local; tokens issued by Central, between :9300 and :9400):**

- M&E read the project's participants from Projects, and an unknown project was refused
  (`unknown_project`).
- M&E's service set a unit to `donors` with that partner, and the change was audited.
- Projects then read the verified result with `audience: donors` and the partner id.
- The unit was set back to `owner`.

**Not verified:** the partner, lead and donor report pages in a browser, and a full
submission → publication chain over HTTP. Both are covered by feature tests only.

## 25. Verification of M4–M7 (2026-11-16, local)

| Suite | Result |
|---|---|
| mel-app Pest, SQLite | 142 passed, 2 skipped (PostgreSQL-only trigger and constraints) |
| mel-app Pest, PostgreSQL (`mel_test`) | 144 passed |
| mel-app Vitest | 42 passed |
| project-management | 455 passed, 7 skipped |
| platform-client | 146 passed |
| central-app | 895 passed, 1 failed, 1 skipped |
| mel-mobile-app Jest | 45 passed |
| Projects ⇄ Finance E2E | 17 of 17 |

- **The Central failure.** `ProductSalesTest` › "shows an ERP solution with its applications and
  plans" expects `public/landing` and gets `public/pharmacy`. It is in the public marketing site,
  which has other uncommitted work in this tree. Central was not changed in M4–M7.
- **Static checks:**
  - PHPStan is clean in mel-app and project-management, and Pint passes.
  - `tsc` is clean in mel-app and mel-mobile-app.
  - `vp check` is clean on the changed files, and ESLint is clean in mel-mobile-app.
  - The mel-app production build succeeds.

## 26. As built (2026-11-18, local, uncommitted): collected data → indicators, and project work

ADR-0012 §11 was not built until now. This section records what was built and where it differs.

**Indicator mappings** (`collect_indicator_mappings`, `App\Collect\Calculations`):

- A mapping ties one published form of a programme to one indicator of the same programme. There
  is at most one active mapping per form and indicator.
- Calculations: `count`, `sum` (of a number question), `average` (sent as numerator Σx and
  denominator n, for ratio and rate) and `percentage` (numerator: a second condition).
  `count_distinct` from ADR-0012 became the general `distinct_by` question: repeat visits with the
  same identifier count once, by the latest completed one, for every calculation.
- Conditions are §4 condition trees, evaluated by the form engine's own `Rules::test`. There is no
  second evaluator. The web editor shows one condition or several joined by "and". It keeps any
  other tree unchanged.
- A mapping that a run used is never edited. A change retires it and creates a new one with
  `supersedes_id`.
- Permissions: defining a mapping needs `mel.frameworks.manage`. Sending a value needs
  `mel.data.enter`. So, by default, a manager defines the calculation and an officer reports it.

**Calculation runs** (`collect_calculation_runs`, `collect_calculation_rows`):

- Only accepted submissions count, by their current answers (the latest revision). The run keeps
  the revision used.
- Submissions are matched to a period by `completed_at`, and to a unit by `programme_unit_id`.
- A preview is worked out on request and saves nothing. Send works it out again under a lock on the
  mapping.
- Send writes the M&E draft through `App\Mel\Submissions::save()`, with `source = collect` and
  `source_reference` = run id. M&E's verification then applies unchanged.
- Send refuses in three cases:
  - an open result entered by hand or imported;
  - a result waiting for verification;
  - nothing to count.
- A new run for the same indicator, period and unit supersedes the earlier ones. Their rows stop
  "standing".
- A partial unique index on `(indicator_id, submission_id) WHERE standing` means a submission
  counts at most once per indicator.
- Reopening a counted submission flags its run ("recalculate"). An accepted submission must be
  reopened before it can be corrected, so this covers corrections too.
- The mappings page shows how many submissions were accepted since a run.
- Traceability: M&E result → run (`collect/calculations/{run}`) → rows → each collected submission
  and revision → form version. The M&E result page links back to its calculation.
- Amounts read from a personal question are hidden without `mel.collect.personal_data`.

**Not built (Phase 2):**

- breakdowns from a select question into an indicator's dimension;
- automatic recalculation;
- calculations for unit roll-ups.

The officer recalculates and sends. For an indicator whose breakdown rule is `must_equal_total`,
M&E's submit step still asks for the cells, which are entered by hand on the draft.

**Project work:**

- `collect_forms.linked_milestone_id` and `collect_assignments.linked_milestone_id` hold a
  milestone of a project linked to one of the programme's units. As with results, it is an id
  only, checked in Projects through the integration API when it changes. It is kept as is while
  Projects is not connected, and cleared when the form leaves its programme. The checks moved from
  `FrameworkController` into `LinkedProjects` and are shared.
- An assignment copies its form's milestone.
- Completing surveys never marks a milestone reached.

**Assignments:** `expected_submissions` is optional. The assignments page shows:

- collection progress (not started / in progress / collected / overdue), from what was sent and not
  rejected;
- review counts apart: to review, to correct.

The phone kit gains `expected` per assignment. It is additive: older apps ignore it.

**Verification:**

- mel-app on SQLite: 156 passed and 2 skipped (143 before; 13 new tests).
- The Collect tests also pass on PostgreSQL 17: 46 of 46.
- PHPStan is clean on the app code, `tsc` is clean, and the JS tests pass (42).
- `vp check` is clean except for three `public/mel` files that were already unformatted.
- The local dev database is migrated. Nothing is committed or deployed.
