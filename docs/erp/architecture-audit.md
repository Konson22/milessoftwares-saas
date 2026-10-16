# ERP transformation — Phase 0 architecture audit

Date: 2026-10-16. Scope: everything in this repository at `bff955f` plus the uncommitted public-site
work in `central-app` (self-serve trials, demo page removal), which was read but not changed.
Brief: `MASTER-PLAN.md` §16.

Method: read the code, migrations, catalogs, tests and docs; ran the central test suite
(535 passed, 1 skipped before this work). Nothing below is inferred from UI alone.

## 1. Application and module inventory

| Path | What it is | Stack | Database | Tests |
| --- | --- | --- | --- | --- |
| `central-app/` | Control plane: public site, IdP (`auth.`), customer dashboard (`app.`), admin (`admin.`), workspace shell (`{slug}.`) | Laravel 13.17, PHP 8.4, Inertia 3 + React 19, TS 5.7, Tailwind 4 | PostgreSQL 17 `miles_platform` | Pest 565 (after this slice), Vitest, PHPStan, Pint |
| `pharmacy-app/` | Pharmacy product at `{slug}/pharmacy` | same | own PostgreSQL DB `pharmacy` | Pest ~80 |
| `finance-app/` | Finance shared module at `{slug}/finance`; receives business events | same | own PostgreSQL DB `finance` | Pest ~33 |
| `packages/platform-client/` | Product SDK: OIDC sign-in, effective access, tenancy guard (`BelongsToTenant`), outbox, webhooks, provisioning | PHP package | — | Pest ~74 |
| `packages/shared-module-kit/` | Event envelope validation, event-token verification, idempotent inbox | framework-free PHP | — | via central suite |
| `contracts/events/` | JSON Schemas for finance/HR/payroll events (v1) | — | — | — |
| `templates/product-app/`, `templates/new-product.php` | Generator for new product apps | — | — | — |
| `examples/sample-app`, `examples/finance-module` | Integration references | plain PHP | SQLite | — |
| `ops/` | nginx (incl. Cloudflare Tunnel), php-fpm, systemd, postgres roles, backup/restore, deploy, k6 | — | — | drills in `docs/PROGRESS.md` |
| `trial-app/` | empty directory | — | — | — |

**Not present, although `MASTER-PLAN.md` assumes them:** Retail POS application, School application,
SUNMI POS integration, Expo React Native app, any procurement/inventory foundation outside
`pharmacy-app`. `retail`, `school`, `hr`, `payroll`, `inventory`, `crm`, `clinic`, `restaurant` exist
only as *planned* registry rows created by the untracked `DemoApplicationsSeeder` (local marketing
filler).

## 2. Confirmed versions and database architecture

- PHP 8.4.26 (local), Laravel `^13.17`, inertia-laravel `^3.0`, @inertiajs/react `^3`, React `^19.2`,
  TypeScript `^5.7`, Tailwind `^4`, Passport 13 + `jeremy379/laravel-openid-connect`.
- PostgreSQL 17 everywhere; Redis (Memurai locally) for cache, sessions, queues.
- **Database per application, shared schema per tenant inside each:** the platform DB holds
  identities, organizations, structure, memberships, registry, roles, plans, subscriptions,
  entitlements, audit. Each product DB holds every tenant's rows with `organization_id`, guarded by
  `BelongsToTenant` (fail-closed global scope). Products never read each other's DB.
- Cross-application consistency is eventual: product outbox → signed event → Finance idempotent inbox
  keyed on `(source_app, organization_id, source_transaction_id)`. No distributed transactions.

## 3. Capability audit

Legend: ✅ implemented and verified by tests · 🟡 implemented, needs improvement · 🟠 partial · ❌ missing ·
♊ duplicated · 🗑 obsolete.

### Control plane (`central-app`)

| Capability | State | Notes |
| --- | --- | --- |
| Identity, OIDC provider, MFA, sessions, passkeys | ✅ | PKCE/state/nonce mandatory; staff MFA enforced |
| Organizations, slugs, tenant resolution, tenant guard | ✅ | composite FKs `(organization_id, id)`; route sweep test |
| Branches, departments, positions | ✅ | an organization is the legal entity; no tenant → many companies level |
| Roles/permissions catalog, scoped assignments, delegation, SoD | ✅ | org/branch/department/resource scopes |
| Plans, bundles, subscriptions, entitlements | ✅ | entitlements are the only access input |
| Self-serve trial (uncommitted) | 🟠 | in the working tree with its own tests |
| Commercial ERP products (product ≠ application) | ❌ → 🟠 | **Phase 1 slice 1** adds `erp_products` (see `product-catalog.md`) |
| Subscription grace / read-only / suspension behaviour | 🟠 | org suspension exists; subscription expiry simply ends entitlements; no read-only mode or export window |
| Provisioning, webhooks, effective-access API | ✅ | idempotency keys, retries |
| Audit (hash-chained, export, verify) | ✅ | |
| Public site product directory | 🟡 | driven by `applications`, not commercial products; under redesign (uncommitted) |

### Business modules

| Capability | Where | State | Notes |
| --- | --- | --- | --- |
| Chart of accounts, journals, reversal-only corrections | finance-app | ✅ | balance re-checked by PostgreSQL at commit |
| AR / AP, customer & supplier payments | finance-app | ✅ | |
| Banking (accounts) | finance-app | 🟠 | no bank reconciliation |
| Financial periods / closing | finance-app | ❌ | nothing prevents posting into a reported month |
| Multicurrency | finance-app | ❌ | single workspace currency |
| Statements (TB, P&L, balance sheet) | finance-app | ✅ | |
| Event posting from products | finance-app + kit | ✅ | invoice, payment, bill, credit note, void |
| Medicine catalogue | pharmacy-app | ✅ | generic/brand, form, strength, unit, barcode, Rx-only, controlled |
| Batches, expiry, FEFO, expired blocked at sale | pharmacy-app | ✅ | append-only stock movements (trigger), non-negative stock (CHECK) |
| Suppliers, purchase orders, goods receipts, supplier returns | pharmacy-app | ✅ | posts bills / credit notes to Finance |
| Counter sale, void, COGS | pharmacy-app | ✅ | posts invoice + payment + COGS |
| Prescriptions, partial dispensing, controlled register | pharmacy-app | ✅ | |
| Branch reports | pharmacy-app | 🟠 | one report page; no cashier reconciliation, supplier balances, item profitability |
| Generic inventory (units/conversions, warehouses, locations, transfers, counts) | — | ❌ | pharmacy stock is per branch; no locations/transfers/counts |
| Generic procurement (PR, RFQ, quotations, approvals) | — | ❌ | pharmacy has PO → GRN → bill only |
| Sales (customers, quotations, orders, credit notes, delivery notes) | — | ❌ | |
| HR, payroll, projects, budgets, documents, approvals engine | — | ❌ | contracts only |
| Retail, Education, NGO | — | ❌ | |

### Duplication and obsolescence

| Item | Finding |
| --- | --- |
| ♊ Suppliers | `pharmacy-app.suppliers` and `finance-app.suppliers` are separate tables linked by events. Acceptable (Finance keeps its payee record); must not grow a third copy for Retail. |
| ♊ `DocumentNumbers`, `Money` | small per-app duplicates; candidates for `platform-client` or a shared ERP package. |
| 🗑 `trial-app/` | empty directory. |
| 🗑 `examples/finance-module` | superseded by `finance-app`; keep only as an integration reference. |
| 🗑 Demo request flow | being removed by the uncommitted public-site work (redirect kept). |

## 4. Central authentication and subscription assessment

- **Fit:** the platform already separates *commercial* (plans, subscriptions) from *access*
  (entitlements → role assignments → product permissions), which is the split the brief asks for.
  Product → module entitlement is expressible as a bundle plan over several applications.
- **Gap (closed by slice 1):** no first-class *commercial product*. Added without a second
  subscription model: products are derived from entitlements (`ProductAccess`).
- **Gap:** no read-only/grace state; `Entitlement::current()` is binary.
- **Gap:** no company level inside an organization (decision D2 in `domain-ownership.md`).
- **No change needed:** SSO, MFA, scoped roles, tenant guard, webhooks, provisioning.

## 5. Reuse matrix

| Need (brief) | Reuse | Do not build |
| --- | --- | --- |
| erp_modules | `applications` (+ `kind`) | a parallel module table |
| subscription_plans / plan_module_entitlements | `plans`, `plan_applications`, `plan_features` | per-product plan tables |
| tenant_product_subscriptions | `subscriptions` + `subscription_items` + `entitlements` | a second subscription table |
| Roles & permissions | catalog import + role assignments | product-local RBAC |
| Accounting | `finance-app` Ledger + event inbox | accounting inside Pharmacy/Retail |
| Pharmacy inventory/procurement | `pharmacy-app` batches, movements, PO/GRN | a second pharmacy stock model |
| Product scaffolding | `templates/product-app` + `platform-client` | hand-rolled product auth |

## 6. Security and operational risk register

| # | Risk | Likelihood | Impact | Mitigation / phase |
| --- | --- | --- | --- | --- |
| R1 | Retail built on a second inventory/procurement engine (today's is pharmacy-local) | High | High | decision D1 before Phase 3 |
| R2 | POS double-post on retry: `POST /sales` has no idempotency key | Medium | High | Phase 7: client key, unique per org |
| R3 | No financial periods: back-dated posting into reported months | Medium | High | Phase 4 |
| R4 | No cashier sessions / till reconciliation | High (pilot need) | Medium | Phase 7/8 |
| R5 | No quarantine / recall status on batches | Medium | High (regulatory) | Phase 8 |
| R6 | Line tables (`sale_lines`, `purchase_order_lines`, …) have no `organization_id` / composite FK | Low | High | only reachable via guarded parents today; composite FKs in Phase 5 |
| R7 | Subscription lapse cuts access instantly; no read-only/export window | Medium | Medium | Phase 1 slice 3 |
| R8 | Public site lists applications; could show a product as available before it is | Medium | Medium | Phase 1 slice 2 |
| R9 | Shared origin across `/{module}` paths | Low | High | existing CSP + first-party-only rule |
| R10 | `npm audit` critical advisories in dev tooling | — | Low | tracked in PROGRESS Phase 7 |
| R11 | Staging sign-off open (load, TLS, GPG drill, pentest) | — | High before go-live | `docs/production-checklist.md` |
| R12 | Brief assumes Retail/School/SUNMI/Expo exist; they do not | Certain | Planning | Phases 10/11 start from zero |
| R13 | Large uncommitted public-site change in the same app as ERP work | Medium | Medium | commit it before slice 2 |
