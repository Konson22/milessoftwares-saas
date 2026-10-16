# ERP product catalog

## Model

```
erp_products (code, name, tagline, description, status, sort_order)
   └─* erp_product_applications (requirement: required | optional) *─ applications
applications ─* plan_applications *─ plans ─* subscription_items ─* entitlements (per organization)
```

- **Product** = what is sold and marketed (Miles Pharmacy ERP). **Application** = what runs
  (`pharmacy`, `finance`). The brief's `erp_modules` is the existing `applications` table.
- Products own **no entitlements**. An organization holds a product while it is currently entitled
  (application-level entitlement, not revoked, in validity) to **every required application** of a
  product whose status is `pilot` or `available` (`App\Modules\Products\Support\ProductAccess`).
  Subscriptions, trials and manual grants keep working unchanged, and the product view can never
  contradict actual access. No duplicate subscriptions, no contradictory grants.
- Status: `planned` (coming soon) · `pilot` (selected customers) · `available` · `retired`.
  A pilot can only require applications in beta or later; an available product only generally
  available ones. Enforced when the catalog is written.

## Source of truth

`central-app/database/erp-products.json`, written by:

```bash
php artisan platform:erp-products            # idempotent; reports each product
```

- Planned products may name applications that are not registered yet; they are left out (and
  reported) until registered. Pilot/available products referencing unknown or platform
  applications are refused; the other products are still written; exit code 1.
- Products missing from the file are left untouched (no deletes from a sync).

Initial catalog:

| Code | Product | Status | Required | Optional |
| --- | --- | --- | --- | --- |
| `pharmacy` | Miles Pharmacy ERP | pilot | pharmacy, finance | — |
| `retail_trading` | Miles Retail & Trading ERP | planned | retail, finance | — |
| `education` | Miles Education ERP | planned | school, finance | hr, payroll |
| `ngo` | Miles NGO ERP | planned | ngo, finance | hr, payroll |
| `finance_accounting` | Miles Finance & Accounting | pilot | finance | — |

Pharmacy is `pilot`, not `available`: see the gaps in `pharmacy-requirements.md`.

## Selling a product (today, no new UI)

Create a bundle plan in admin covering the product's required applications; assigning the plan
derives the entitlements, and `ProductAccess` then reports the product. A plan ↔ product link
and a check that a product plan covers every required application are slice 2.

## Next slices (Phase 1)

2. Public site and pricing read `erp_products` (status badges: available / pilot / coming soon);
   `plans.erp_product_id` (nullable, additive) with the coverage check; admin read-only product list.
3. Subscription lapse behaviour: grace and read-only window (`entitlements` stay the input),
   documented and exposed in the effective-access API.
