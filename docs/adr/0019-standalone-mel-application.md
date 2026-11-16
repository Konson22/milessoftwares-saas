# ADR-0019: Tatook M&E as a standalone application, extracted from Projects

- Status: **Accepted** (2026-11-16), with the recommended answer to each open decision (§15).
  **M1 is built locally** and uncommitted (§16). Nothing is deployed, and `project-management`
  is unchanged.
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
| **M2** | Central: `mel` registered, catalog split (`mel` 1.0.0, `projects` 4.0.0), products, plans, marketing; PM: M&E removed (routes, pages, navigation, module) | PM suite green without M&E; M&E-only, PM-only and both tested locally |
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

## 16. As built: M1 (2026-11-16, local, uncommitted)

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
- **Catalog.** `mel-app/platform/catalog.json` is `mel` 1.0.0, kind `standalone`: 25 permissions,
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
