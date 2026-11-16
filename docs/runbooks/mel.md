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

While M&E ran inside Projects, a reporting unit could be linked to a project and its figures read
live. In the standalone application the link is off (`LinkedProjects::enabled()` is false) until
the integration (ADR-0019 §9, M3): no project figures in M&E, and no M&E results in partner
reports. Partner reports submitted before keep the results they captured.

## Roles

| Role | Can |
| --- | --- |
| M&E administrator | Everything in M&E, including settings and the audit trail |
| M&E manager | Programmes, frameworks and indicators; verify and publish |
| M&E officer | Enter, import and review results across programmes; cannot publish |
| Field officer | Enter and submit results for the programmes and units they are given |
| Enumerator | Collect data with the forms they are assigned |
| Project manager | Follow the programmes they are given (linking units to projects returns with M3) |
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
