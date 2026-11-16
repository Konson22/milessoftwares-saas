# Tatook M&E (`mel-app`): first deploy — NOT DEPLOYED YET

Tatook M&E as its own application (ADR-0019): code `mel`, folder `mel-app`, at
`{workspace}/mel`, with its own database, php-fpm pool, queue and catalog. Since M2B, Projects no
longer serves M&E (catalog `projects` 4.0.0), so `mel-app` is deployed **before** that Projects
release. **The deploy needs the owner's approval**; nothing here moves or copies existing M&E data
(production never had any, ADR-0019 §1.2). Operations after go-live: `docs/runbooks/mel.md`.

It deploys like any product (`docs/runbooks/deploy.md`, Products; `deploy.sh`'s `product_dir`
maps `mel` to `mel-app` by default). What is specific to it:

## Central, before the first deploy

1. **Kind.** Production may already hold an application `mel` from the earlier `shared_module` era
   (catalog 1.0.0). The importer never changes an existing application's kind, and while `mel` is a
   shared module the marketing catalog and trials treat M&E as having no application of its own.
   With staff approval, once:

    ```bash
    php artisan tinker --execute='App\Modules\Applications\Models\Application::where("code","mel")->first()?->forceFill(["kind"=>"standalone","name"=>"Tatook M&E"])->save();'
    ```

2. **Catalog 2.0.0** (`mel-app/platform/catalog.json`: 25 permissions, 9 roles, 3 separation-of-
   duties rules) is imported by the deploy. 2.0.0 exists because 1.0.0 was already published with
   different content; published versions are immutable.
3. **Products and plans** (`database/erp-products.json`, then `php artisan platform:erp-products`):
   Monitoring & Evaluation requires `mel` and lists `projects` as optional; NGO ERP lists `mel` as
   optional. The plans `mel-standard` and the Projects + M&E bundle need **approved prices** before
   they are made public: `LocalPlansSeeder` is local only, and the bundle is a draft without a price.
4. Admin → Applications → `mel`: the integration endpoint `https://{internal}/mel/platform`,
   sign-in client with tenant redirect path `/mel/auth/callback`, service client, webhook secret.
   Copy the values into the product's `.env`. Trials are per application: switch the trial on for
   `mel` only when approved.
5. **Projects ⇄ M&E integration (ADR-0019 §20), optional.**
   - Central must run the release with the `integration.read` scope and
     `POST /api/v1/integration-tokens`.
   - `mel-app` `.env`: `PLATFORM_PROJECTS_INTEGRATION_URL=https://{internal}/projects/integration/v1`.
   - `project-management` `.env`: `PLATFORM_MEL_INTEGRATION_URL=https://{internal}/mel/integration/v1`.
     It is not in PM's `.env.example` yet; add it there when that file is next committed.
   - Leave either empty to keep that direction off.
   - Both URLs are server to server. They need not be public, but `/…/integration/v1` must reach
     the app without the workspace host check.

## Server, before the first deploy

1. Database, owned by `miles_owner`: `sudo -u postgres psql -v db_name=mel -f ops/postgres/product-database.sql`.
2. `/srv/miles/shared/products/mel/.env` (0600 `miles:miles`) from `mel-app/.env.example`:
   `APP_ENV=production`, `APP_DEBUG=false`, new `APP_KEY`, `DB_DATABASE=mel`,
   `DB_USERNAME=miles_app`, `PLATFORM_ROOT_DOMAIN=tatook.com`, the `PLATFORM_*` client values.
3. Storage `/srv/miles/shared/products/mel/storage/{app,framework/{cache,sessions,views},logs}`.
   **Evidence files, import sheets and form attachments live in `storage/app/private`**: back it up
   with the database. A verified result's evidence cannot be uploaded again.
4. nginx `ops/nginx/products/mel.conf` → `/etc/nginx/miles-products/`, `nginx -t`, reload (22 MB
   bodies, 120 s). php-fpm `ops/php-fpm/mel.conf` into the pool directory, reload.
5. Add `mel` to `PRODUCTS` for the deploy, then
   `systemctl enable --now miles-product-queue@mel miles-product-scheduler@mel.timer`.

## After the first deploy

- Provisioning: an organization gets M&E settings (name, currency, time zone from Central) and the
  Sex and Age group dimensions when it is entitled; nothing else is created.
- Check the database protections as the runtime role once there are rows: the statements in
  `docs/runbooks/mel.md` ("Checking the database protections") must fail.
- Access rules, as verified locally in M2A: a workspace without `mel` is not visible to M&E (404);
  a member without an M&E role gets 403; revoking `mel` cuts open sessions off at the next request
  (403) and removes the workspace's sign-in callback at Central, so a new sign-in is refused
  (`invalid_client`); data is kept and returns with re-entitlement.

## Data Collection, field app and desktop (ADR-0019 §21–§24)

- **Migrations.** `2026_11_17_090000` (Data Collection intake) and `2026_11_17_120000` (who
  results are released to in project reports) are additive. They add columns, PostgreSQL CHECK
  constraints and one trigger. Both run with the deploy.
- **Field app sign-in.**
  1. Register the field app's public client in Central: admin → Applications → `mel`, a sign-in
     client, public, with the redirect URIs of `mel-mobile-app` (scheme `com.tatook.mel`).
  2. Put its id in `PLATFORM_MOBILE_CLIENT_ID` in mel-app's `.env`, and in
     `EXPO_PUBLIC_CLIENT_ID` for the app's build.
  - Until this is done, `{ws}/mel/api/v1/*` answers 404 by design.
- **Field API limits.** 300 requests a minute per user (`throttle:collect-api`). Photos are at most
  10 MB, or twice the workspace's photo limit, whichever is smaller. Photos are stored in
  `storage/app/private/collect/{org}/{submission}/`: back them up with evidence files.
- **Exports** are generated synchronously (at most 50,000 rows) into
  `storage/app/private/collect/{org}/exports/`. Downloads stop after 7 days; the files can be pruned after
  that.
- **Desktop app (PWA).**
  - `public/mel/manifest.webmanifest`, `sw.js`, `offline.html` and `icons/` must be served as
    static files at `/mel/…`.
  - nginx must not cache `/mel/sw.js` for long: `Cache-Control: no-cache` on that file.
  - It needs HTTPS (or localhost) to install.
  - When `offline.html` or the icons change, bump `VERSION` in `sw.js`, so installed copies take
    the new ones.
- **Results in partner reports.** Who sees a unit's results in a project's reports is set on the
  programme page (`mel.projects.link`). Nothing changes until someone sets it: every unit starts at
  "project owner only". Projects must run the release with `GET integration/v1/projects/{id}/participants`.