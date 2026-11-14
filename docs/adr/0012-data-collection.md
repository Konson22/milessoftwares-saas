# ADR-0012: Data Collection is a module of Tatook M&E, with its own form engine

- Status: **Proposed** (2026-11-14, revised for the module brief). Nothing is built yet.
  Implementation waits for approval.
- Code: module `App\Collect` inside **`mel-app`** (registry application `mel`). Web at
  `{workspace}/mel/collect/*`; mobile API at `{workspace}/mel/api/v1/collect/*`. Field app:
  **`mel-mobile-app`** (Expo, Android first).
- No new application registration, subscription, identity service or database.

## 1. Assessment of what exists

**Platform.** Each product is a Laravel 13 + Inertia/React app with its own PostgreSQL database.
Sign-in goes through central OIDC (`miles/platform-client`). Access comes from catalog permissions
plus the effective-access API, which supports plan features. Tenancy is `BelongsToTenant`, which
fails closed. Composite FKs `(organization_id, id)` are written with `TenantSchema::foreign`.

**M&E (`mel-app`)** is built locally (through f7c9b90), and is neither pushed nor deployed.

| Need | Reused as it is | Added |
|---|---|---|
| Programmes, "projects" | `programmes`; `programme_units` (kind project / site / partner, optional `linked_project_id` → PM snapshot) | forms get optional `programme_id` and `programme_unit_id` |
| Who may work where | `App\Mel\Access` + `programme_access` (unit and window grants, 404 outside) | form assignments for enumerators (§7) |
| Reporting periods | `reporting_periods` per programme | a filter by period's date range on `completed_at` |
| Indicators | `indicators`, `indicator_versions` (measures count, number, percentage, rate, ratio, currency, yes_no; numerator/denominator for fractions), `indicator_breakdowns` on `dimensions` | mappings and calculation runs (§10) |
| M&E verification | `submissions` (with `source` / `source_reference`, unique per org), `review_decisions`, `verified_results`, `App\Mel\Review` | a new source value `collect` |
| Files | `evidence_files` (private disk, sha256) pattern | `collect_attachments`, same storage pattern |
| Audit | `mel_audit_events` (append-only trigger) + `AuditTrail` | new event names |
| Excel | `App\Support\Spreadsheet` (OpenSpout; text always in text cells, so no formulas) | a CSV writer with formula-injection escaping |
| Charts, fields, UI | `components/charts.tsx`, `components/fields.tsx`, `@miles/ui` shell, `lib/navigation.ts` | a "Data collection" nav group |
| Events in from PM | `/mel/events`, inbox | nothing |
| Mobile token API | **none**: `mel-app` has web routes only | `routes/api.php` with `platform.api` (as restaurant) |

**Name clashes:** M&E already owns `submissions`, `review_decisions` and the *Verification* menu.
Collection tables therefore take a `collect_` prefix, and the review of collected data is called
**Review** (inside Submissions). "Verification" stays M&E's word for indicator results.

**Mobile.** Pharmacy, restaurant, petrol and retail use Expo SDK 57, Expo Router, NativeWind and
zustand. Reused from petrol: PKCE sign-in (tokens in `expo-secure-store`), workspace choice, and the
outbox pattern. That pattern is a client UUID, server replay from a submissions table, and a
"try later" vs "refused" classification, plus the sync bar and outbox screen. Their queues sit in
AsyncStorage (unencrypted, size-capped, no files), so the field app uses `expo-sqlite` instead, which
`retail-mobile-app` already uses. **Decision: a dedicated M&E field client, `mel-mobile-app`.** No
existing app fits: they are point-of-sale apps for other products, each with its own client and
store listing.

**No form builder, dynamic form or custom-field code exists anywhere** in the repository, so nothing
is duplicated.

**Build vs integrate.** ODK Central is ruled out. It is Apache 2.0, so the licence is fine, but:

- it brings its own users and permissions, which means a second identity system;
- its only boundary between organizations is "projects", with no fail-closed guard;
- it needs Docker, which production does not run;
- ODK Collect is an unbranded Kotlin app.

We build a small engine whose definition follows XLSForm concepts one to one, so XLSForm
import/export (Phase 2) is a translation.

## 2. Module boundaries

The module is built so it can be lifted out for another Tatook app later:

- **Code:** `app/Collect/` (actions: Forms, Definitions, Rules, Publishing, Assignments, Intake,
  Review, Exports, Calculations), `app/Models/Collect/`, `routes/collect.php`, `routes/api.php`,
  `resources/js/pages/collect/` and `resources/js/collect/` (the rules engine and renderer, shared
  with the phone).
- **Tables:** all are `collect_*`. Collect references M&E only through `programmes`,
  `programme_units`, `indicators`/`indicator_versions`/`reporting_periods` (mappings only) and
  `users`. M&E references Collect only through `submissions.source = 'collect'` and its
  `source_reference`.
- **Gate:** plan feature `mel.collect` turns the module on (nav, routes, API).

**Navigation**, a new group *Data collection*:

- Overview
- Forms & surveys (the builder opens from a form: `collect/forms/{form}/build`)
- Field assignments
- Submissions (tabs: All, Review queue)
- Exports

Indicator mappings live on the indicator's page (*Data sources*). Their calculated results go to
M&E's existing *Verification*.

## 3. Form definition (`schema_version: 1`)

One JSON document per version. The XLSForm vocabulary is used:

```json
{
  "schema_version": 1,
  "settings": {
    "title": "WASH household survey",
    "consent": { "text": "We will ask about your household's water…", "required": true }
  },
  "items": [
    { "type": "group", "name": "water", "label": "Water", "items": [
      { "type": "yes_no", "name": "safe_water", "label": "Does the household have access to safe drinking water?", "required": true },
      { "type": "select_one", "name": "main_source", "label": "Main drinking water source", "choices": "sources",
        "relevant": { "q": "safe_water", "op": "eq", "value": "no" }, "required": true },
      { "type": "integer", "name": "members", "label": "People in household", "required": true,
        "constraint": { "min": 1, "max": 50 } },
      { "type": "date", "name": "visit_date", "label": "Visit date", "constraint": { "max": "today" } }
    ]}
  ],
  "choices": { "sources": [ { "name": "river", "label": "River or stream" }, { "name": "vendor", "label": "Water vendor" } ] }
}
```

- **MVP types:** `text`, `textarea`, `integer`, `decimal`, `select_one`, `select_multiple`, `yes_no`,
  `date`, `time`, `datetime`, `geopoint`, `photo`, `note`, `group`.
- **Reserved for later** (refused by the v1 validator, with no database change needed): `audio`,
  `video`, `signature`, `barcode`, `repeat`, `matrix`, `rank`, `calculate`, `geotrace`, `geoshape`. A
  phone that meets an unknown type refuses the form ("update the app") and never skips it.
- **Names** follow XLSForm rules (`^[A-Za-z_][A-Za-z0-9_]{0,63}$`) and are the stable identifiers. A
  label can change between versions while the name stays.
- **Consent** (optional per form) is shown before the first question. Declining ends the form and
  saves nothing. A flag records that consent was given on each submission.

## 4. Logic and validation engine

**Rules are data, never code.**

- A condition is a JSON tree: `{ "all": [...] }`, `{ "any": [...] }`, `{ "not": c }`, or a leaf
  `{ "q": name, "op": op, "value": v }`.
- Operators: `eq`, `ne`, `gt`, `gte`, `lt`, `lte`, `answered`, `not_answered`, `includes` (for
  select_multiple) and `in`.
- `relevant` (visibility) and `required_if` take a condition.
- `constraint` holds only:
  - `min` / `max` for numbers, and for dates either a literal or `"today"` / `"today-30d"`;
  - `min_length` / `max_length`;
  - `min_choices` / `max_choices`;
  - a `pattern` picked from a fixed list (digits, phone).

  There is no free regex, no expression strings and no eval.
- A hidden question's answer is cleared and never validated. Notes and groups hold no answer.
- **Today on the server** is the submission's `completed_at` date (when it was filled), not its
  arrival date, so a form completed offline last week still validates.

**One semantics, two implementations, one suite.** There is a PHP evaluator
(`App\Collect\Rules`) and a TS evaluator (`resources/js/collect/rules.ts`, copied byte for byte into
the phone app by a script that CI checks). `contracts/collect/rules-cases.json` holds the
conformance cases (definition + answers → visible, required, errors), and both Pest and Vitest run
every case.

**Publish checks** (`App\Collect\Definitions`):

- every type is known to v1;
- names are unique across the form;
- every reference points to an **earlier** question of a compatible type (`includes` only on
  select_multiple, number ops only on numbers and dates, `eq` against an existing choice). Cycles are
  impossible by construction, and a graph check runs anyway;
- choice lists exist and their names are unique;
- constraints suit the type;
- groups are not empty;
- the form stays within 500 items and 1 MB.

The builder runs the same checks live through the TS engine, so errors show before Publish.

## 5. Lifecycle and versions

**Form states:** `draft → published → archived`. A published form can be edited: its
`draft_definition` diverges, and Publish makes version n+1.

- **Publish** validates the draft and writes `collect_form_versions` (number, definition, sha256,
  publisher, note). A trigger refuses UPDATE of `definition`, and DELETE of a version any submission
  references. The FK is `RESTRICT` too.
- **Submissions keep their version.** Each one stores `form_version_id`, and display, validation,
  review and export use **that** version.
- **Exports across versions** take the union of names, plus a version column. A question whose type
  changed gets its own column (`members__v3`).
- **Phones pick up new versions** when the kit lists each assigned form's current version id and
  hash, and the phone downloads what it lacks. Drafts begun on an older version stay on it.
- **Accepting old versions.** The server accepts a submission on any version of the form unless that
  version was *closed*. Closing a version happens on Publish, or later with an optional
  "accept until" date. After that the server answers `409 version_closed`, and the phone shows
  *Action required* (§9). Answers are never migrated between versions.
- **Archive** hides the form from phones and lists, refuses new submissions after an optional grace
  date, and keeps every version and submission for reports. **Duplicate** copies the current
  definition into a new draft form.

## 6. Database (additions to the `mel` database)

Conventions as in ADR-0011: UUIDv7, `timestamptz`, `organization_id` + `BelongsToTenant`, composite
FKs, `CHECK` constraints on statuses, and no hard deletes outside the retention job.

**Settings.** `workspaces` gains:

- `collect_reviewer_must_differ` (default true);
- `collect_photo_max_kb` (default 800);
- `collect_keep_on_device_days` (default 30);
- `collect_retention_months` (default null, which means keep).

**Forms**

- `collect_forms`: name, description, purpose (`survey`, `assessment`, `monitoring_visit`,
  `inspection`, `research`, `other`), optional `programme_id` and `programme_unit_id`, status,
  `draft_definition` jsonb, `draft_updated_at`, `current_version_id`, `archived_at`,
  `accept_until`, and `contains_personal_data` (default true; it drives export warnings).
- `collect_form_versions`: form, number (unique per form), definition jsonb, sha256, `published_by`,
  `published_at`, note, `closed_at`, `accept_until`.

**Assignments**

- `collect_assignments`: form, user, `starts_on`, `ends_on`, status (`active`, `closing`, `revoked`),
  `closes_at`, assigned/revoked by and when, and an optional `programme_unit_id` (default unit on the
  phone). Unique `(form_id, user_id)` while active.

**Submissions**

- `collect_submissions`:
  - `id` is the **client UUID**, so it is the idempotency key;
  - form, form version, programme and unit (copied from the form or chosen on the phone), author;
  - status (`receiving`, `submitted`, `under_review`, `correction_requested`, `accepted`,
    `rejected`);
  - `started_at` / `completed_at` (device), `received_at` (server);
  - `answers` jsonb (the **original**, immutable by trigger) and `content_hash`;
  - `consent_given`, plus `lat` / `lng` / `accuracy` from the first geopoint;
  - `app_version`, `attachments_expected`, `attachments_received`;
  - `current_revision_id`.

  Indexes: `(organization_id, form_id, status)`, `(organization_id, author_id, completed_at)`,
  `(organization_id, programme_id, completed_at)`, and a GIN index on `answers`.
- `collect_submission_revisions`: number, the full `answers`, `changed` (the names that differ), a
  required reason, author, time. They are append-only. The *current answers* are the latest
  revision's, otherwise the original.
- `collect_attachments`: submission, question name, revision (null for the original), sha256, size,
  MIME, private path, `received_at`. Unique `(submission_id, question_name, revision_id)`.
- `collect_review_decisions`: append-only. Decisions: `review_started`, `accepted`, `rejected`,
  `correction_requested`, `resubmitted` and `reopened`, with comment, user and time.

**Exports**

- `collect_exports`: requester, form, filters jsonb, format (`csv`, `xlsx`), scope (`current` or
  `original` answers), status, rows, private path, `expires_at` (7 days), downloads.

**Indicators (§10)**

- `collect_indicator_mappings`: indicator, form, calculation jsonb, filters jsonb (a §4 condition),
  breakdown mapping jsonb, status (`draft`, `active`, `retired`), author. They are versioned by
  `supersedes_id`, and a mapping used by a run is never edited.
- `collect_calculation_runs`: mapping, reporting period, unit, the values calculated (value, or
  numerator and denominator, plus breakdown cells), eligible and used counts, status (`preview`,
  `sent`, `discarded`), `mel_submission_id`, user and time.
- `collect_calculation_rows`: run × submission (+ the revision used). Unique
  `(organization_id, indicator_id, submission_id)` among sent runs, so no response counts twice for
  one indicator.

Audit uses `mel_audit_events`.

**Answers as jsonb, deliberately.** There is no table per form and no EAV. Filters use the
structured columns, plus a GIN index for "question = value". Exports stream and flatten rows by
version. Calculations read only the questions a mapping names. If heavy analytics come later, an
`answer_facts` table can be rebuilt from the jsonb at any time, because the jsonb stays the source of
truth.

## 7. Permissions and scope

**Catalog `mel` 1.1.0 adds:**

| Permission | Meaning |
|---|---|
| `mel.collect.view` | forms and submissions within reach |
| `mel.collect.design` | create, edit, duplicate, preview forms |
| `mel.collect.publish` | publish, close versions, archive |
| `mel.collect.assign` | manage field assignments |
| `mel.collect.submit` | download assigned forms and submit (the phone) |
| `mel.collect.review` | start review, accept, reject, request correction |
| `mel.collect.correct` | make corrections to submissions in reach |
| `mel.collect.export` | run and download exports |
| `mel.collect.personal_data` | see answers to questions marked personal, photos and exact GPS; without it they show as "hidden" |

**Reach (`App\Collect\Scope`, built on `App\Mel\Access`):**

- A **form with a programme** is in reach if the programme is (grant or `mel.programmes.all`), and,
  for a unit grant, the unit matches.
- A **form without a programme** is in reach for holders of `mel.programmes.all`, and for people
  assigned to it.
- **Enumerators only ever see forms assigned to them,** never "everything in the programme". The
  phone kit and the submission API check the assignment, not just the permission.
- Anything out of reach answers 404.

**Roles:**

| Role | Change |
|---|---|
| Enumerator (new) | `collect.submit` only |
| Field officer | gains `collect.submit` |
| M&E officer | gains view, design, assign, review, export |
| M&E manager / Administrator | gain everything |
| Donor viewer, Independent evaluator | gain nothing: no respondent data |

**SoD:** the pair `collect.submit` ⟂ `collect.review` (warn), plus the transaction rule that the
reviewer is not the author (setting, on by default).

**Questions marked personal:** the builder has a `personal: true` flag (names, phone numbers, IDs),
and photos and geopoints are always treated as personal.

## 8. Mobile app (`mel-mobile-app`)

**Scaffold.** Copied from `petrol-mobile-app`: sign-in, workspace choice, theme, sync bar, outbox
screen. It needs its own EAS project, a platform mobile client (`MOBILE_CLIENT_ID` in `mel-app`) and
Android OAuth clients.

**Storage.** `expo-sqlite` with **SQLCipher**. The key is random, generated on first launch and kept
in `expo-secure-store`. Tables: `form_versions`, `assignments`, `drafts`, `queue`, `attachments`,
`history`. Every write is a transaction, and the draft is saved on every answer change.

- Photos are files in the app's private document directory, re-encoded on capture (long edge
  ≤ 1600 px, within `collect_photo_max_kb`), which also strips EXIF.
- Nothing unsent lives in memory or AsyncStorage.
- Each record is scoped to `workspace|user`, so another person on the same phone sees none of it.
- An app lock (device PIN or biometrics, `expo-local-authentication`) applies when the app resumes
  after 5 minutes, if the workspace turns it on.
- The SDK 57 plugin options are checked against the versioned docs first (`AGENTS.md`).

**Screens:**

- assigned forms (downloaded / update available / closed);
- fill form: one group per screen, large controls, validation on Next, a progress bar, and
  "Saved · 2 s ago";
- drafts;
- outbox: Ready to sync / Syncing / Failed / Action required / Synced;
- history;
- account.

GPS shows its accuracy and a retry. Photos open the camera, preview, and allow retake.

## 9. Synchronization protocol

**Phone states** are independent of the server's review states:

`Draft → Ready to sync → Syncing → Synced`, plus **Failed** (temporary, retried automatically) and
**Action required** (a person must act).

1. **Send the submission.** `POST api/v1/collect/submissions` with
   `{id, form_version_id, programme_unit_id?, answers, consent_given, started_at, completed_at, app_version, attachments: [{question, sha256, size, mime}]}`.
   - The server checks the assignment and the version, validates with `Rules`, then inserts.
   - Same id and same `content_hash` → `200` with the first answer and `replayed: true`.
   - Same id and a different hash → `409 id_conflict` (Action required, logged).
   - With attachments listed, the status is `receiving`. The response lists the files still missing.
2. **Send each file.** `PUT api/v1/collect/submissions/{id}/attachments/{question}`. The server checks
   sha256 and size against the manifest, and a repeat is a no-op. When the last file arrives,
   `receiving → submitted` in the same transaction.
3. **Confirm.** `Synced` only once the server reports `submitted`. **A request with no reply is never
   treated as failed or as done.** The next attempt re-sends the same id, and the replay answer
   settles it. The phone then deletes the photos and keeps a history row. The answers are dropped
   after `collect_keep_on_device_days`.

**Retry.** Exponential backoff with jitter (30 s up to 30 min), plus *Retry now*. A connectivity
change triggers a sync. Petrol's `tryLater` classification decides:

- offline, timeout, 429 and 5xx → **Failed**, retried;
- 401 → refresh the token once, then wait for sign-in;
- 422 / 403 / 409 → **Action required**, with the server's reason.

**Recovery procedures:**

| Case | What happens |
|---|---|
| Interrupted upload | Resume from the server's list of missing files. The submission row already exists |
| Expired session | The refresh token lasts 30 days. Past that the queue waits, and sends once **the same user** signs in again |
| Revoked assignment | Assignments are *closed* by default (no new forms; finished ones accepted until `closes_at`). If revoked: 403, Action required, read-only. An administrator can reopen the assignment to recover the data. The server decides by server time and assignment state, never device timestamps |
| Unsupported / closed version | 409 `version_closed`: Action required. The administrator can extend `accept_until`. Drafts on a closed version can be viewed but not finalized |
| App too old for a form | The form is refused at download, with "Update the app" |
| Corrupted draft (unreadable row, missing version) | It is quarantined, shown under Action required, never deleted automatically, and the raw record can be shared as a file for support |
| `receiving` on the server for 30 days | Flagged to the form's administrators, never deleted |
| Sign out with unsent data | Lists what would be lost and needs a typed confirmation |

## 10. Review of collected data

**Workflow:** `submitted → under_review → accepted`. From `submitted` or `under_review`:

- **Reject:** the data is excluded, and a reason is required. The record is never deleted.
- **Request correction:** comments per question. The author corrects the submission on the phone or
  the web, which makes a revision, and it returns to `submitted`.

An accepted submission is locked by trigger. *Reopen* (with `collect.review`) needs a reason, makes
a revision, and returns the submission to review. An accepted revision that already feeds a sent
calculation run flags that run (§11).

- Every decision writes `collect_review_decisions` + `mel_audit_events` in one transaction, under
  `lockForUpdate`.
- The reviewer is not the author (setting).
- The detail page shows each answer **rendered by the submission's own version**, the revision diff,
  metadata (author, device time, server receipt time, app version, GPS accuracy), attachments
  through an authorized controller, and the decision history.
- Rule checks are re-run, and any "missing or invalid" findings are highlighted.

## 11. Indicator mapping and calculation

**Data flow:** Survey → Submission → **Accepted** → Mapping → Calculation run → M&E draft submission
→ M&E verification.

A mapping is created from the indicator's *Data sources* tab by someone with
`mel.frameworks.manage` + `mel.collect.view`:

- **Form and questions:** only from published versions. A question is compatible if it has the same
  name and a compatible type in every version used.
- **Calculation:**

| Type | Gives | Allowed for measure |
|---|---|---|
| `count` | number of eligible submissions | count |
| `count_distinct` | distinct values of a question (household ID) | count |
| `sum` | Σ a numeric question | count, number, currency |
| `average` | Σx / n, stored as **numerator Σx and denominator n** | ratio / rate (M&E has no average measure, and averaging averages is wrong) |
| `percentage` | numerator: count matching a condition; denominator: count eligible | percentage |
| `ratio` | numerator and denominator from two declared sums or counts | ratio, rate |

- **Filters:** a §4 condition (for example `safe_water = no`), plus the unit and the period.
- **Period:** `completed_at` within the M&E reporting period's dates. The unit is the submission's
  `programme_unit_id`.
- **Breakdown:** a select_one question → an M&E dimension (each choice → a category). It must match
  one of the indicator version's breakdowns, and M&E's reconciliation rule applies.

**Run:**

1. *Preview* shows the value and cells, eligible / excluded counts with reasons (not accepted,
   filtered out, already counted for this indicator), and a link to every contributing submission.
2. *Send to verification* writes an M&E `submissions` draft, with `source = 'collect'` and
   `source_reference = run id`, and the run's rows. The unique key on
   `(indicator, collect submission)` prevents double counting. A replayed or re-synced submission is
   the same UUID, so it can never count twice.
3. It then goes through M&E's normal review → verify → publish, unchanged. **Accepted survey data
   never becomes a verified achievement by itself.**

**Traceability:** verified result → M&E submission → run → rows → each collected submission and
revision → form version. A correction accepted after a run was sent flags that run as
"source changed" and offers an M&E correction. It never edits a verified result.

## 12. Exports and reporting

- **Exports** run on the queue and are written to the private disk. They are audited, re-authorized
  at download, and expire after 7 days. There is one column per question name (`select_multiple` →
  one 0/1 column per choice, plus a joined column), plus metadata columns.
- **Personal data.** Without `mel.collect.personal_data`, personal columns are left out, and the
  export says so in a notes row.
- **Formats.** XLSX uses `App\Support\Spreadsheet` (always text cells). CSV escapes any cell that
  starts with `=`, `+`, `-`, `@`, tab or CR by prefixing `'`.
- **Overview page:**
  - forms (draft / published);
  - submissions in total, this week, and by day (chart);
  - by form;
  - by enumerator;
  - review status (submitted, under review, correction requested, accepted, rejected);
  - `receiving` (the only "pending sync" the server can see; phone queues are not claimed);
  - active enumerators (7 days).
- **Per-form summary:** counts and percentages for choice and yes/no questions, min / median / max
  for numbers, and answered counts. Free text, photos and GPS are not summarized.

## 13. Security and privacy

- Tenant isolation through `BelongsToTenant` + composite FKs, with cross-tenant tests on every new
  route and API endpoint.
- Programme, unit and assignment reach through `App\Collect\Scope`, which answers 404.
- Backend checks in route middleware (`can:`) **and** in actions. The UI only reflects them.
- HTTPS only. Bearer tokens last 10 minutes. The phone stores no device identifiers.
- Attachments are streamed only through an authorized controller, never from public URLs.
- Personal-data gating (§7). Donor and evaluator roles see no respondent data. Consent text per form.
- **Retention:** a scheduled job purges answers (keeping metadata and an audit stub) and attachments
  of submissions older than `collect_retention_months`, for forms that are archived or past
  `accept_until`. Values already sent to M&E stay as aggregates.
- Audit events cover publish, assignment changes, every review decision, every export and download,
  every attachment view, mappings and runs.

## 14. Scope

**MVP (brief §18):** steps 3–7 below. Indicator mapping (step 8) follows once submissions are
stable.

**Phase 2:** advanced question types, XLSForm import/export, multilingual, repeats, calculations,
GIS maps, data-quality rules, public surveys, mapping enhancements, Kobo/ODK interop.

**Not in the MVP:** web-based data entry. The web renderer is used for preview and for corrections
only.

## 15. Implementation sequence

Each step is committed locally on branch `products/mel-collect`, and nothing is deployed. Each step
ends with `composer ci:check`, `npm run build` and the M&E suite.

3. **Foundation:**
   - `collect_*` migrations, triggers and models;
   - catalog 1.1.0 + Enumerator role;
   - plan feature `mel.collect`;
   - `Scope`;
   - nav group;
   - form list and create / duplicate / archive;
   - the JSON Schema and `rules-cases.json`.
4. **Builder:**
   - `Definitions` + `Rules` (PHP), `rules.ts` + renderer (TS), both on the conformance cases;
   - builder (palette, outline with drag-and-drop, properties panel, logic editor that picks
     questions and values, live preview, autosave);
   - publish, versions, history;
   - assignments.
5. **Mobile collection:**
   - `mel-app` token API (session, kit, version download);
   - `mel-mobile-app` scaffold;
   - SQLCipher storage, renderer, drafts, consent;
   - GPS (`expo-location`) and photos (`expo-camera`);
   - finalize.
6. **Synchronization:**
   - the submission and attachment API with idempotency;
   - phone queue, retry, recovery cases;
   - Jest tests with a transport that drops requests before and after the server commits.
7. **Submissions:** list and filters, detail, review queue, request correction and revisions,
   exports, Overview, per-form summary.
8. **Indicator mapping:** mappings, preview, runs, sending to M&E verification, traceability links.
9. **Quality:**
   - the full suite on SQLite **and** local PostgreSQL 17 (triggers, GIN);
   - Vitest + Jest;
   - a browser walk-through of the builder and review;
   - device tests (airplane mode, killing the app mid-sync, a throttled network), reported as done or
     not done;
   - update `docs/runbooks/mel.md`, the README and the marketing entry.

## 16. Testing

**Pest:**

- definition validation and the conformance cases;
- publish, and version immutability (trigger, FK restrict);
- submissions on old versions and closed versions;
- assignment reach (not assigned → 404; closing; revoked);
- tenant isolation on every route;
- replay, id conflict, partial attachments and resumption;
- expired token;
- the review workflow, accepted lock, request correction and revisions;
- attachment and export authorization, personal-data gating, CSV escaping;
- mapping compatibility, every calculation type, no double counting, run → M&E draft;
- that all existing M&E tests still pass.

**Vitest / Jest:**

- the conformance cases;
- the queue state machine;
- persistence across a database reopen mid-upload;
- the lost-reply replay;
- attachment resumption;
- corrupted-draft quarantine.

## 17. Risks

- **PHP/TS rules drift:** shared conformance cases, and a CI check that the phone's copy matches.
- **A new offline engine is unproven:** keep the type list short and pilot with one organization.
  M&E itself has not met a customer yet.
- **SQLCipher on SDK 57:** confirm it in the versioned docs; it needs a development build. The
  fallback (plain SQLite under Android file encryption + app lock) is weaker, and would be stated.
- **Mixed load in one app:** photo uploads share M&E's php-fpm pool. Mitigated by size caps, a
  separate rate limit for the mobile API, and queued exports. The module's boundaries (§2) allow
  moving it out.
- **SQLite tests vs PostgreSQL:** triggers and GIN need the pgsql run (credentials in
  `mel-app/.env`).
- **Device clocks:** `completed_at` decides the period and "today" in validation, and never security.
- **Storage and backups:** `storage/app/private` is already in M&E's backup scope, and will grow with
  photos.
- **Working tree:** `products/mel-app` holds unrelated uncommitted changes (central access pages,
  petrol roles). The module branch stages only its own paths.
- **Mobile release:** EAS project, platform mobile client, Android OAuth clients, Play listing. Device
  tests cannot happen before those exist.
