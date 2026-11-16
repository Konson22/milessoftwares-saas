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
