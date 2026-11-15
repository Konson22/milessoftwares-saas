# M&E and Data Collection: go-live and operations

Monitoring & Evaluation and its Data Collection module are part of Tatook Projects
(`project-management`, ADR-0013), at `{workspace}/projects/mel`. They deploy with Projects
(`docs/runbooks/deploy.md`, Products) and use its database: tables `mel_*` and `collect_*`. This
page is what is specific to them.

## On the first deploy that includes M&E

1. **Back up the Projects database first** (`docs/runbooks/backup-restore.md`). The release adds
   tables only (migrations `2026_11_15_1000*`–`1004*`) and drops `mel_project_snapshots`, which
   was never deployed.
2. ops: the updated `ops/nginx/products/projects.conf` (22 MB bodies for evidence, 120 s for
   imports and exports) and `ops/php-fpm/projects.conf` (21 MB uploads, 125 s), then `nginx -t`,
   reload nginx and the `projects` pool.
3. **Evidence files, import sheets and form attachments live in Projects'
   `storage/app/private`**: make sure it is in the backups with the database. A verified result's
   evidence cannot be uploaded again.
4. The deploy imports `project-management/platform/catalog.json` **3.0.0**: Projects' roles are
   unchanged, M&E's nine roles are added. Then on central:
   `php artisan platform:catalog-reassign projects --dry-run`, then without `--dry-run`.
5. Give someone the **M&E administrator** role. M&E is a module of Projects that is on by default
   (Administration → Settings → Modules); owners see it at once, everyone else once they hold an
   M&E role.
6. Nothing else: there is no separate M&E app, database, queue or event link.

The first time an organization opens M&E, its M&E settings are made (name, currency, time zone
from the organization) with two starting dimensions: Sex, and Age group in bands.

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

- A reporting unit can be linked to one of the organization's projects (Programme → unit →
  Project). Its status, progress, approved budget and spending are read live from the project:
  nothing is copied or synced. A result can point at one of a linked project's milestones.
- Project progress is operational progress. It is shown next to results, never mixed into them.

## Roles

| Role | Can |
| --- | --- |
| M&E administrator | Everything in M&E, including settings and the audit trail |
| M&E manager | Programmes, frameworks and indicators; verify and publish |
| M&E officer | Enter, import and review results across programmes; cannot publish |
| Field officer | Enter and submit results for the programmes and units they are given |
| Enumerator | Collect data with the forms they are assigned |
| Project manager | Follow the programmes they are given; link units to projects |
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
- Nobody sees M&E in the menu: check that the module is on (Administration → Settings) and that
  they hold an M&E role.
