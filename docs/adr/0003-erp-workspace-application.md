# ADR-0003: One ERP workspace application for shared business modules

- Status: Accepted (2026-10-16)
- Phase: ERP Phase 3 onwards (`MASTER-PLAN.md` §12)

## Context

The ERP products (Pharmacy, Retail & Trading, Education, NGO) need shared Inventory, Procurement and
Sales. The brief forbids a second engine for any of them and prefers a modular monolith for business
transactions. The only engine today is inside `pharmacy-app` (batches, append-only stock movements,
purchase orders, goods receipts, supplier returns, counter sales), and it already posts to Finance
through the idempotent event inbox.

Options considered:

1. **Grow `pharmacy-app` into the ERP workspace application**: shared `Inventory`, `Procurement`,
   `Sales` modules inside it, Pharmacy as an industry module on top, Retail added later as another
   industry module in the same application and database.
2. A shared Composer package used by separate Pharmacy and Retail applications.
3. A separate inventory service.

## Decision

Option 1. Stock and the document that moves it stay in one database transaction; there is one copy
of each kind of record per organization; nothing new has to be deployed. Finance stays a separate
application fed by events (already idempotent, no distributed commit); merging it is not part of
this decision.

## Consequences

- From Phase 3, `pharmacy-app` code is reorganised into module namespaces (shared modules vs
  `Pharmacy`) without changing behaviour; its existing tests are the safety net. The app and
  database are renamed only when Retail joins, as a separate, planned change.
- Each industry module is its own registry application (own catalog, own entitlement) served from
  the same deployment, so Pharmacy and Retail are still sold and permitted separately.
- Option 2 and option 3 are not to be revisited without a new ADR.
