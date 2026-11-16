# M&E and Data Collection: go-live and operations

Tatook M&E, with Data Collection, is its own application (ADR-0019): code `mel`, folder `mel-app`,
at `{workspace}/mel`, with its own database (tables `mel_*` and `collect_*`), php-fpm pool and
queue. Its first deploy is `docs/runbooks/mel-app.md`; this page is how to run it. Projects no
longer serves M&E (catalog `projects` 4.0.0).

When an organization is entitled to M&E, Central provisions it: its M&E settings are made (name,
currency, time zone from the organization) with two starting dimensions, Sex and Age group in bands.

## Checking the database protections

As the runtime role, once there are rows (with no rows the statements succeed trivially):

```sql
-- All must fail.
UPDATE mel_audit_events SET action = action;          -- "append-only"
UPDATE mel_review_decisions SET comment = comment;    -- "append-only"
UPDATE mel_verified_results SET value = value;        -- "cannot be changed; submit a correction"
DELETE FROM mel_verified_results;                     -- "never deleted"
```

## Projects and M&E

The two applications are linked by live reads only (ADR-0019 §20). Neither writes the other's
database, and nothing is copied or synchronised.

**Turning it on.**

- M&E reads Projects when `PLATFORM_PROJECTS_INTEGRATION_URL` is set in `mel-app`'s `.env`
  (`https://{internal}/projects/integration/v1`). Projects reads M&E when
  `PLATFORM_MEL_INTEGRATION_URL` is set in `project-management`'s `.env`
  (`https://{internal}/mel/integration/v1`). Either can be on alone.
- Both service clients need the Central scope `integration.read`, which every service client may
  request.
- Central issues a read token only while the organization is active and entitled to **both**
  apps. When the acting member is named, they must be an active member.

**In M&E.**

- A manager with `mel.projects.link` links a reporting unit to a project. The project is checked
  in Projects as that member: it exists, and they may see it there.
- Results can point to a milestone of a linked project, checked the same way.
- Project figures and milestones are read live as the member viewing the page. Money is shown only
  to members who hold `projects.finance.view` in Projects.

**In Projects.**

- Linking shares nothing. A manager also ticks **"Report published results to the project owner"**
  on the unit before any of its results leave M&E. The box is off by default, is audited, and is
  cleared when the unit is unlinked or moved.
- When a partner report with a period is submitted, Projects reads the **verified, published,
  standing** results of the shared units (non-draft indicators), for periods overlapping the report
  period. It records them in the report's snapshot with `melSource` (status, retrieval time,
  correlation id).
- The read is made by Projects for the project's owner organization. The submitting partner gets
  no M&E access.
- **Only the project owner's members see the recorded results.** Implementers, leads,
  sub-partners and donors see the report without them, also after publication (ADR-0019 §20.6).
  Showing them to partners or donors is a pending business decision, not a setting.
- Snapshots are frozen with the report version; later changes in M&E only affect later reports.

**When something fails, it says what happened; it never shows a zero.**

| What the page or report shows | Cause | What to do |
| --- | --- | --- |
| "Your organization does not have both Projects and M&E" / `not_entitled` | an entitlement is missing | entitle both, or nothing to do |
| `subscription_expired` | the M&E (or Projects) entitlement ran out | renew |
| "You do not have access to Projects" / `denied` | the member has no Projects role, or is not an active member | give a Projects role |
| "Projects cannot be reached" / `unavailable`, `timeout` | the other app is down or slow (3 s limit, one retry on 502/503/504) | check the other app; links are kept |
| "Linked to a project you cannot see…" | team-only project, or no longer in Projects | none: the link and results are kept |
| `unlinked` / `not_shared` / `no_results` | the answer itself: no unit linked; linked but not set to report; nothing published for the period | link a unit, tick the sharing box, or publish |
| `no_period` | the report has no period, so M&E was not asked | give reports a period |
| `not_set_up` | the organization never opened M&E | open M&E once |

**Tracing a read.** Both sides log `Integration read` / `Integration request` with the same
`correlation_id` (also in the `X-Correlation-Id` response header and in the report's
`melSource`).

Partner reports submitted before M3 keep what they captured; they have no `melSource`.

## Roles

| Role | Can |
| --- | --- |
| M&E administrator | Everything in M&E, including settings and the audit trail |
| M&E manager | Programmes, frameworks and indicators; verify and publish |
| M&E officer | Enter, import and review results across programmes; cannot publish |
| Field officer | Enter and submit results for the programmes and units they are given |
| Enumerator | Collect data with the forms they are assigned |
| Project manager | Follow the programmes they are given; link units to projects they may see in Projects |
| Implementing partner | Enter and submit results for their own units |
| Donor viewer | Published results and evidence marked shareable, for the programmes and periods given |
| Independent evaluator | Verified and published results with evidence, for the programmes and periods given; changes nothing |

Roles without "every programme" see nothing until they are given a programme (Programme →
Access). All of one person's grants on a programme share one period window. Under the default
settings, whoever submitted a result cannot verify it (M&E settings).

## A result's life

```
draft ──submit──▶ submitted ──verify──▶ verified ──publish──▶ published
  ▲                   │
  └──correct──── rejected (with a reason)
```

- A verified result is never edited. A **correction** is a new submission for the same cell; it
  replaces the old figure only when it is itself verified, and both stay on record.
- Reports to donors use published results only; internal reports use verified ones. Drafts and
  submitted figures never appear in a report.
- Changing an indicator's definition creates a new version once results exist under the old one.
  Periods reported under different versions are shown side by side, not combined.

## When something is stuck

- An import that reports errors applies nothing: fix the sheet and upload it again. Only the person
  who uploaded a sheet can apply it.
- "Not combined" in a report means the indicator's rules do not add units together (or a part of a
  percentage is missing). That is by design; there is no programme-wide total or percentage.
- A donor who sees nothing: check their grant (programme, units, periods) and that the results are
  published, not just verified.
- Nobody can open M&E: check that the organization is entitled to `mel` (a workspace without it is
  not visible to M&E, 404) and that they hold an M&E role (403 otherwise).
