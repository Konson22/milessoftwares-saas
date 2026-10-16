# ADR-0004: A company is an organization

- Status: Accepted (2026-10-16)
- Phase: ERP Phase 2 onwards (`MASTER-PLAN.md` §5)

## Context

The brief describes Tenant → Company → Branch → Department. The platform models an organization as
one legal entity with branches and departments; role scopes, tenant guards, composite foreign keys,
product databases and Finance's books are all keyed on `organization_id`.

Options considered:

1. **One organization per legal company**, with several organizations grouped under a parent
   account for a business that runs more than one company.
2. A `companies` level inside an organization, with branches re-parented under companies.

## Decision

Option 1. Each company keeps its own books, stock, roles and isolation with no change to any
existing table or product. A user who works for two companies already holds two memberships and
switches between them.

## Consequences

- No schema change now. The grouping (parent account: shared billing, an owner who can see all its
  companies, consolidated reporting) is designed when the first multi-company customer needs it.
- A company administrator never reaches another company's data, because each is a separate tenant
  (brief §5: "Do not automatically allow company administrators to access unrelated companies").
- Consolidated group reporting will read across organizations through Finance's API, never
  through a cross-tenant query.
