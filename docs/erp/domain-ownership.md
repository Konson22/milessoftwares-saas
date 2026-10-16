# Canonical domain ownership

One owner per record type. Others read through the owner's API, events or webhooks; nobody writes
another owner's tables. Platform-side ownership is unchanged from `docs/architecture.md` §2.

| Domain | System of record today | State | Consumers | Target |
| --- | --- | --- | --- | --- |
| Identity, MFA, sessions | central-app `Identity` | ✅ | every app (OIDC) | unchanged |
| Organizations (tenant = legal entity) | central-app `Organizations` | ✅ | every app | decision D2 |
| Branches, departments | central-app `Structure` | ✅ | products via effective-access API | unchanged |
| Roles, permissions, assignments | central-app `Applications` + `Authorization` | ✅ | products | unchanged |
| Applications (technical modules) | central-app `applications` | ✅ | launcher, provisioning, gateway | unchanged |
| **ERP products (commercial)** | central-app `Products` (`erp_products`) | new (Phase 1 slice 1) | public site, dashboard (later slices) | — |
| Plans, subscriptions, entitlements | central-app `Subscriptions` / `Entitlements` | ✅ | products via API/webhooks | add grace/read-only |
| Chart of accounts, GL, AR/AP, banking | finance-app | ✅ | every product (events) | add periods, reconciliation, FX |
| Items, batches, stock movements | pharmacy-app `Inventory` module (`items`, Phase 3.2) | ✅ | Procurement, Sales, Pharmacy | shared inventory engine (D1) |
| Medicines (pharmacy details of an item) | pharmacy-app `Pharmacy` module | ✅ | — | stays Pharmacy-specific |
| Suppliers (purchasing view) | pharmacy-app | ✅ | finance-app via bill events | shared master data (Phase 3) |
| Suppliers (payables view) | finance-app | ✅ | — | keeps payee data, linked by reference |
| POs, goods receipts, supplier returns | pharmacy-app | ✅ | finance-app | shared procurement (D1) |
| Sales, voids | pharmacy-app | ✅ | finance-app | shared sales/POS (D1) |
| Prescriptions, controlled register | pharmacy-app | ✅ | — | stays Pharmacy-specific |
| Customers | — (free-text name on a sale) | ❌ | — | shared Sales (Phase 3/7) |
| HR, payroll | — | ❌ | contracts only | Phase 10 |
| Audit (platform) | central-app `Audit` | ✅ | admin | products keep their own operational history |

## Decision D1 — where shared Inventory, Procurement and Sales live (accepted: ADR-0003)

The brief forbids a second inventory/procurement engine and prefers a modular monolith for
business transactions. Today the only engine is inside `pharmacy-app`.

1. **Recommended — grow `pharmacy-app` into the ERP workspace application** (renamed later):
   shared `Inventory`, `Procurement`, `Sales` modules inside it, `Pharmacy` as an industry module on
   top, Retail added later as another industry module in the same app and database. One DB
   transaction covers stock + document; Finance stays separate and event-fed (already idempotent,
   no distributed commit).
2. Shared Composer package used by separate Pharmacy and Retail apps: two databases holding the
   same kinds of records, plus package-version drift.
3. Separate inventory service: rejected by the brief (no microservices without need).

## Decision D2 — companies inside a tenant (accepted: ADR-0004)

The platform treats an organization as one legal entity with branches. The brief's
Tenant → Company → Branch needs either (a) one organization per company, grouped under a parent
account, or (b) a `companies` level inside an organization with branches re-parented. (a) reuses
everything; (b) touches structure, role scopes and every product. Recommended: (a).
