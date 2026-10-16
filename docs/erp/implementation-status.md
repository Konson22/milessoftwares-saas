# Implementation status

| Phase | Status |
| --- | --- |
| 0 Audit | Done 2026-10-16 |
| 1.1 Product catalog foundation | Done 2026-10-16 (uncommitted) |
| 1.2 – 11 | Not started |

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

**Next-phase dependencies:** commit the pending public-site work; decisions D1 and D2.
