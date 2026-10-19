# Implementation roadmap

Re-sized to what exists (see `architecture-audit.md`). Every phase: tests, Pint, PHPStan, type
check and build green; migrations rolled back and forward on the test DB; results recorded in
`implementation-status.md`.

| Phase | Scope | Measurable acceptance criteria |
| --- | --- | --- |
| 0 Audit | this folder | inventory, ownership, risks, plan written ✅ |
| 1 Product catalog | 1.1 products + derived access ✅ · 1.2 site/pricing/admin read products, plan ↔ product ✅ · 1.3 grace/read-only | 1.1: catalog sync idempotent, release rules enforced, product access only with all required entitlements, cross-tenant test · 1.2: no product shown as available unless `status=available`; product plan cannot omit a required application · 1.3: lapsed org can read and export, cannot post (tests in a product) |
| 2 Workspace foundation | entitlement-filtered navigation across apps, branch context shared via platform, reusable layout from `templates/product-app` | navigation hides unentitled/unpermitted items and every hidden route still 403/404s (route sweep per app) |
| 3 Master data | **after decision D1**: units/conversions, customers, suppliers under shared modules | one supplier record per org per party; pharmacy flows unchanged (existing tests green) |
| 4 Finance | periods & closing, bank reconciliation, FX design | posting into a closed period refused at DB level; reconciliation matches statement lines |
| 5 Inventory | warehouses/locations, transfers, counts, valuation report, composite FKs | stock value = Σ movements × cost; transfer is two movements in one transaction |
| 6 Procurement | requests, approvals, quotations on the shared engine | GRN → stock → bill reconciles; approval SoD tested |
| 7 Sales & POS | idempotent sales, cashier sessions, discounts, receipts, partial returns | replayed POST creates one sale; session close variance reported; returns post to Finance |
| 8 Pharmacy | quarantine/recall, packaging, disposal approval, compliance items | recalled batch cannot be sold/dispensed (DB + app tests) |
| 9 Reports, security, pilot | pharmacy & finance reports, staging sign-off (`docs/production-checklist.md`), pilot with ≥1 customer | month-end reports equal Finance ledger on seeded data; checklist signed |
| 10 Other products | Retail → Finance standalone GA → Education → NGO, one at a time | each starts at `planned`, moves to `pilot` only with its own acceptance list |
| 11 Mobile/offline | Expo, offline POS, SUNMI | after Phase 9 pilot; sync idempotency proven |

## Decisions

- **D1** accepted: [ADR-0003](../adr/0003-erp-workspace-application.md) (grow `pharmacy-app`
  into the ERP workspace application; the code reorganisation starts in Phase 3).
- **D2** accepted: [ADR-0004](../adr/0004-companies-as-organizations.md) (a company is an
  organization).
- **D3** accepted: [ADR-0005](../adr/0005-hr-and-payroll-application.md) (HR and Payroll as one
  separate application, `hr-app`; Payroll a plan feature). Phases in [hr-roadmap.md](hr-roadmap.md),
  run alongside this roadmap.

## Recommended next slice

Phase 1.3: subscription lapse behaviour, a grace and read-only window exposed in the
effective-access API, with products refusing writes while read-only.
