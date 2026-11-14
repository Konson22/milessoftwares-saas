# M&E: go-live and operations

Monitoring & Evaluation is `mel-app` at `{workspace}/mel` (ADR-0011), with its own database. It
deploys like any product (`docs/runbooks/deploy.md`, Products); this page is what is specific to it.

## Before the first deploy (once, on the server)

1. Database, owned by `miles_owner`:

    ```bash
    sudo -u postgres psql -v db_name=mel -f ops/postgres/product-database.sql
    ```

2. `/srv/miles/shared/products/mel/.env` (0600 `miles:miles`) from `mel-app/.env.example`:
   `APP_ENV=production`, `APP_DEBUG=false`, a new `APP_KEY`, `DB_DATABASE=mel`,
   `DB_USERNAME=miles_app`, and the `PLATFORM_*` values.
3. Storage: `/srv/miles/shared/products/mel/storage/{app,framework/{cache,sessions,views},logs}`.
   **Evidence files and import sheets live in `storage/app/private`**: include it in the backups
   with the database. A verified result's evidence cannot be uploaded again.
4. Register the application in admin → Applications (code `mel`) for its sign-in and service
   clients and webhook secret, then copy those values into its `.env`.
5. nginx: `ops/nginx/products/mel.conf` into `/etc/nginx/miles-products/`, `nginx -t`, reload
   (it allows 22 MB bodies for evidence). php-fpm: `ops/php-fpm/mel.conf` into the pool
   directory, reload.
6. Make sure `mel` is in `PRODUCTS` for the deploy. Until step 4 is done, the `miles-deploy`
   wrapper leaves it out ("not registered yet, left out: mel").

## After the first deploy

- `systemctl enable --now miles-product-queue@mel miles-product-scheduler@mel.timer`.
- The deploy imports `mel-app/platform/catalog.json` (1.0.0). Give someone the
  **M&E administrator** role. Under the default settings, whoever submitted a result cannot
  verify it, and whoever verified it cannot publish it (Settings).
- Check, as the runtime role, that the database protections are in place:

    ```sql
    -- All must fail once there are rows.
    UPDATE mel_audit_events SET action = action;     -- "append-only"
    UPDATE review_decisions SET comment = comment;   -- "append-only"
    UPDATE verified_results SET value = value;       -- "cannot be changed; submit a correction"
    DELETE FROM verified_results;                    -- "never deleted"
    ```

  (No rows yet: the statements succeed trivially. Check again after the first verified result.)

## Roles (catalog 1.0.0)

| Role | Can |
| --- | --- |
| M&E administrator | Everything, including settings and the audit trail |
| M&E manager | Programmes, frameworks and indicators; verify and publish |
| M&E officer | Enter, import and review results across programmes; cannot publish |
| Field officer | Enter and submit results for the programmes and units they are given |
| Project manager | Follow the programmes they are given; link units to Projects |
| Implementing partner | Enter and submit results for their own units |
| Donor viewer | Published results and evidence marked shareable, for the programmes and periods given |
| Independent evaluator | Verified and published results with evidence, for the programmes and periods given; changes nothing |

Roles without "every programme" see nothing until they are given a programme (Programme →
Access). All of one person's grants on a programme share one period window.

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
