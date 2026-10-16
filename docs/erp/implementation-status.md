# Implementation status

| Phase | Status |
| --- | --- |
| 0 Audit | Done 2026-10-16 |
| 1.1 Product catalog foundation | Done 2026-10-16 (`88eb513`) |
| 1.2 Products on the site, plans sell products | Done 2026-10-16 |
| 1.3 – 11 | Not started |

Decisions accepted 2026-10-16: D1 → `docs/adr/0003-erp-workspace-application.md`,
D2 → `docs/adr/0004-companies-as-organizations.md`.

## Phase 1.1 — 2026-10-16 (local, Windows)

**Implemented** (`central-app`, new module `App\Modules\Products`):
- Migration `2026_10_16_100000_create_erp_product_tables` (additive): `erp_products`,
  `erp_product_applications`, CHECKs on code/status/requirement, application delete restricted.
- `ProductStatus` enum with release rule; `ErpProduct` model; `ProductCatalog` (validated,
  idempotent sync); `ProductAccess` (products an organization holds, derived from entitlements);
  `platform:erp-products` command; `database/erp-products.json` (5 products).

**Reused:** `applications`, plans/subscriptions/entitlements unchanged; `TenantContext`,
`Entitlement::current()`.

**Not changed:** public site, admin UI, any product app, the uncommitted public-site work.

**Tests executed:**
- `tests/Feature/Products/ProductCatalogTest.php`: 29 passed (50 assertions).
- Full central suite: 564 passed, 1 skipped (pre-existing CREATEROLE skip); baseline before the
  slice 535 passed, 1 skipped.
- Pint pass; PHPStan 0 errors.
- Migration up → rollback → up on `miles_platform_test`: all DONE.
- Mutation checks: product access with "any" instead of "every" required application fails 5
  tests; removing the release-status check fails 3 tests.
- Frontend untouched; no build needed.

**Known limitations:** products not yet visible anywhere in the UI; no plan ↔ product link; the
dev database has not been migrated or synced (run `php artisan migrate` and
`php artisan platform:erp-products` when ready).

**Remaining risks:** see `architecture-audit.md` §6 (R1–R13).

**Next-phase dependencies:** commit the pending public-site work (done, `9bb5d1b`); decisions D1
and D2 (accepted).

## Phase 1.2 — 2026-10-16 (local, Windows)

**Implemented** (`central-app`):
- Migration `2026_10_16_110000_add_erp_product_to_plans` (additive): nullable
  `plans.erp_product_id`; deleting a product that has plans is refused.
- `ProductPlans` rule: a plan selling a product must include every required application, nothing
  outside the product, and may be active only for a pilot or available product. Enforced in admin
  plan create/update and by `platform:erp-products` (a catalog change that would break a
  non-retired plan is refused with a `plans` error).
- Admin: read-only **Products** page (`/products`, `plans.manage`); ERP product select on plans.
- Public site: "ERP products" section on the home page, in the nav and footer; product pages at
  `/erp/{code}` (applications shown as included / optional add-on, plans for the product);
  badges Pilot / Coming soon; retired products 404; sitemap entries. Plans that sell a product
  name it and lead to Contact instead of a single-application trial. `/products/{code}` URLs
  unchanged.

**Tests executed:**
- `tests/Feature/Products`: 45 passed (144 assertions); with the billing admin tests: 56 passed.
- Full central suite: 580 passed, 1 skipped (pre-existing).
- Vitest 35 passed (accessibility checks now cover the ERP product page and product plans);
  `vp check` clean; `tsc` clean; Pint; PHPStan 0 errors; `npm run build` OK.
- Migrations: rollback 2 steps → migrate on `miles_platform_test`: DONE.
- Mutation checks: removing the catalog's plan guard fails 2 tests; skipping the admin coverage
  check fails 3 tests.

**Known limitations:**
- No self-serve product trial: a trial covers one application and a product needs several, so
  every product call to action goes to Contact.
- Not checked live in a browser; the dev database has not been migrated or synced. To see it:
  `php artisan migrate`, register the pharmacy and finance applications, then
  `php artisan platform:erp-products`.
- The contact form does not record which product the visitor asked about.

**Next:** Phase 1.3, subscription lapse behaviour (grace / read-only window), then Phase 2.
