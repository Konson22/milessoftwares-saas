# ADR-0023: Tatook Procurement, a standalone application

Status: **Accepted (2026-11-17). P1 built locally, not deployed.**
Follows the M&E precedent (ADR-0019). It does not change ADR-0020 (procurement inside Projects).

## 1. Architecture assessment (verified in code, 2026-11-17)

| Question | Finding |
|---|---|
| How apps are organized | One repository. Each product is its own Laravel 13 + Inertia/React app (`*-app/`, `project-management/`), generated from `templates/product-app` |
| Separate backends and databases | Yes. Each product has its own PostgreSQL database (`mel`, `projects`, `finance`, …) and its own php-fpm pool, served at `{workspace}.milessoftwares.com/<code>` |
| Organization and tenant identity | Central (`central-app`) owns organizations. Products resolve the workspace from the host (`platform-client` `ResolveTenant`) and scope every row by `organization_id` (`BelongsToTenant`, fail closed, composite tenant FKs via `TenantSchema`) |
| Authentication | Central is the OIDC provider. Products sign in with PKCE through `platform-client`. There are no product passwords |
| Subscriptions | Central entitlements, per application. An unentitled workspace answers 404 in the product (`ResolveTenant`). Revocation reaches open sessions |
| Roles and permissions | Per-application catalog (`platform/catalog.json`: permissions, roles, separation-of-duties), imported by Central. Assignments are made in Central and read through effective access on every request |
| App-to-app communication | (a) **Outbox events** (`platform-client` `Outbox`): idempotent by source transaction, retried, skipped when the org is not entitled to the receiver. Finance consumes `finance.*` events. (b) **Integration tokens** (Central-signed, per pair, acting member). Only projects⇄mel is allowed today. |
| API gateway | None. nginx routes paths to each product |
| Deployment | `ops/deploy/deploy.sh` (`miles-deploy` on the VPS) builds every product listed in `PRODUCTS`. There are nginx templates in `ops/nginx/products`, pools in `ops/php-fpm`, and a CI job per product |
| Shared supplier directory | **None.** Finance's `suppliers` is the ledger's list of counterparties, keyed by `(source_app, source_supplier_id)`, and filled by products' bill events. Projects and Pharmacy have their own supplier tables |
| Inventory application | **None.** The marketing catalog lists Inventory as "planned". Stock lives inside the Pharmacy/Retail/Restaurant ERPs |

### Where procurement lives today

| App | What | Bound to |
|---|---|---|
| `project-management` (ADR-0020, in production) | Purchase requests, quotes, PO, receipts with rejection, supplier invoices, close-short | **Project budgets.** `purchase_orders.expense_id` is NOT NULL. The PO's expense *is* the commitment against budget line, grant and Finance spending (ADR-0017/0020) |
| `pharmacy-app` (Pharmacy and Retail) `Modules/Procurement` | Stock purchase orders and goods receipt. Emits `finance.bill.received` | **Stock.** Receipts move inventory valuation |
| `finance-app` | Suppliers, bills, supplier payments, payment requests | Accounting (authoritative for AP and payments) |
| `petrol-app` | Fuel deliveries | Tank stock |

None of these supports organization-level purchasing: departments, plans, RFQs and tenders, evaluation, award approval. ADR-0020 §3 deferred "non-project purchasing" and "RFQs and bid evaluation" for this reason.

## 2. Decision

1. **A new product `procurement-app`.**
   - Application code `procurement`, path `/procurement`, local port :9500.
   - Generated from `templates/product-app`, with its own PostgreSQL database `procurement`.
   - A modular monolith with namespace `App\Procurement`, in logical modules: Plans, Requisitions, Sourcing, Evaluation and Awards, Orders, Receiving, Invoices, plus the shared Approvals, Audit and Documents.
   - No microservices, no broker, no new infrastructure.
2. **Identity, subscription and roles come from Central.** The catalog is `procurement` 1.0.0, with 16 permissions, 9 roles and separation-of-duties warnings. The app holds no account or billing code.
3. **The procurement-only path is complete.** Every step from plan to closure works with no other entitlement.
4. **Suppliers are owned by Procurement, inside its boundary.**
   - No shared directory exists to reuse.
   - When Finance is connected, suppliers reach Finance **one way**, by id, on the bill event. Finance's `firstOrCreate` on `(source_app, source_supplier_id)` makes this safe to repeat.
   - No second writable copy exists.
5. **Money.** Each document keeps:
   - its own currency;
   - the rate in force when it was entered, from the workspace's entry currencies (`platform-client` `EntryCurrencies`, the rates shared by all products);
   - amounts in both its own currency and the workspace currency.
   Comparisons and approval thresholds use the workspace currency. Procurement keeps no ledger.
6. **Approvals are configurable.**
   - Rules match on document type, amount band (workspace currency), department and method.
   - Each rule has ordered steps. A step names an approve permission or a specific member.
   - With no matching rule, a single default step applies: the type's approve permission.
   - The submitter never approves their own document unless settings allow it.
   - One person never approves two steps of the same document.
   - Every decision is kept.
7. **Finance integration (scenario B), opt-in.**
   - It is a settings switch, off by default.
   - A supplier invoice that is matched sends `finance.bill.received` v1 through the outbox. A match means within the value accepted on receipts (three-way: PO, receipt, invoice). Service acceptance counts as receipt evidence.
   - Lines name the account code configured in settings.
   - Finance then owns the liability and the payment. Procurement no longer lets people mark that invoice paid.
   - The idempotency key is `supplier_invoice:{id}`. Finance bills are also unique by `(source_app, number)`, so no duplicate liability can arise.
8. **Projects (scenario D).**
   - P1 keeps a project and funding *reference*: free text plus an optional external id. Projects data is not duplicated.
   - A live link needs Central to allow the integration-token pair `procurement → projects`, and a Projects endpoint. Planned as P3.
9. **Inventory (scenario C).**
   - There is no Inventory application to integrate with.
   - Receipts carry stable ids. The planned event `inventory.goods.accepted` v1 is keyed `receipt_line:{id}`. It will be emitted once Inventory exists. Nothing is built against a counterpart that does not exist.
10. **Procurement inside Projects is not migrated in P1.**
    - Its orders *are* project budget commitments, and Finance spending requests depend on them.
    - Moving them needs the commitments split that ADR-0020 §3 deferred as high financial risk.
    - Coexistence is deliberate and bounded:
      - Projects keeps *project-funded* purchasing.
      - Procurement covers *organization* purchasing.
    - Cutover plan: §5.
    - Pharmacy/Retail stock purchasing stays in the ERPs, where stock is valued.

## 3. Statuses

| Document | Flow |
|---|---|
| Plan | draft → pending_approval → approved → (revise → draft, revision+1) → closed |
| Requisition | draft → pending_approval → approved / rejected / returned (editable, resubmit); cancelled; approved → amend (back to draft, revision+1, only with no live sourcing or PO); closed |
| Sourcing event | draft → (pending_approval when a rule exists) → issued → closed → evaluation → awarded / cancelled |
| Award | pending_approval → approved / rejected |
| Purchase order | draft → pending_approval → approved → issued → partially_received → received → closed; cancelled before any receipt; amend (approved/issued → draft, revision+1, snapshot kept, quantities never below received) |
| Supplier invoice | recorded (match: matched / awaiting_receipt) → voided; payment: unpaid / paid_externally (manual, labelled "recorded by hand, not verified") or Finance-owned |

## 4. Data integrity

- Every table is tenant-keyed: `organization_id` plus a composite FK to its parent.
- Numbers are unique per organization, drawn from a locked per-org sequence.
- State changes run in transactions with row locks.
- Receipts never exceed what was ordered.
- Invoices never exceed the order. Duplicate supplier invoice numbers are refused (normalised).
- The audit trail is append-only (PostgreSQL trigger).
- Records referenced by transactions are archived, never deleted (suppliers: active / inactive / suspended).

## 5. Migration and cutover (Projects procurement, P4)

1. P1: register and run Procurement on its own. Nothing in Projects changes.
2. P3: allow the integration pair procurement→projects in Central. Projects exposes budget lines and accepts a **commitment request** from Procurement, replacing the internal `raiseOrder`.
3. P4: the `commitments` table (ADR-0020 §3). Projects purchase requests then read from Procurement.
4. A one-time copy of Projects purchase requests, quotes, orders, receipts and invoices into Procurement, keyed by the Projects id (`source_ref`):
   - safe to repeat;
   - counts reconciled per org;
   - read-only checks first.
5. Projects procurement becomes read-only, then is retired once verified.
6. Rollback at any step: the Projects tables are never dropped. Re-enable the Projects routes.

## 6. Roadmap

- **P1 (this ADR, built):**
  - application, catalog and registration files;
  - plans, requisitions, approval rules, suppliers, sourcing (all methods on one engine), quotations with currency;
  - evaluation (mandatory pass/fail plus scores), award approval;
  - POs (approve, issue, amend, cancel, print);
  - receiving (goods and services);
  - supplier invoices with three-way match and external payment status;
  - Finance bill handoff (opt-in);
  - dashboard, reports and CSV, documents, audit, and tests.
- **P2:**
  - Finance payment status back to Procurement (needs a Finance event);
  - partial awards per line;
  - framework agreements (call-offs);
  - weighted price/technical scoring;
  - supplier portal.
- **P3:** Projects live link. **P4:** cutover (§5). **P5:** Inventory, once it exists.

## 7. As built (P1, 2026-11-18)

Not deployed. Uncommitted in the working tree, for review.

### 7.1 Where the code is

- **App:** `procurement-app/`, from `templates/new-product.php`.
  - Code `procurement`, path `/procurement`, local port 9500.
  - Database `procurement`.
- **Domain:** `app/Procurement/`, one class per stage:
  - `Plans`, `Requisitions`, `Suppliers`, `Sourcing`, `Orders`, `Receiving`, `Invoices`;
  - `Approvals` (the rule engine), `Access`, `Documents`, `Figures`, `Money`, `Numbers`, `AuditTrail`.
- **Controllers** are thin. Every route carries a `can:` permission from the catalog.
- **Catalog 1.0.0:** 16 permissions, 9 roles, 4 separation-of-duties rules. The rules are `warn`; the app enforces them itself (§7.3).
- **Pages:** Inertia React on `@miles/ui`.
  - Overview, approvals inbox, requisitions, plans, suppliers;
  - sourcing with the comparison and evaluation;
  - orders with receiving and invoices, a printable PO;
  - reports with CSV, the audit trail, and settings (rules, limits, currencies, Finance).
- **Central registration:**
  - `erp-products.json` gets product `procurement`. It is `planned` and requires `procurement`, with `finance` optional.
  - `LocalPlansSeeder` gets `procurement-standard`, with no price set.
  - The marketing catalog's `registry` points at the application.
- **Ops:**
  - `ops/nginx/products/procurement.conf` and `ops/php-fpm/procurement.conf`;
  - a local Apache proxy on :9500;
  - CI job `procurement`, run on both PostgreSQL and SQLite;
  - runbook `docs/runbooks/procurement-app.md`.

### 7.2 Tables

Every table is tenant-scoped, with composite `(organization_id, id)` keys. The migration is additive and creates a new database, so no existing data is touched.

- `suppliers`
- `approval_rules` and `approvals`
- `procurement_plans` and `procurement_plan_items`
- `requisitions` and `requisition_lines`
- `sourcing_events`, `sourcing_items`, `sourcing_invitations`
- `evaluation_criteria`, `quotations`, `quotation_lines`, `evaluation_scores`, `awards`
- `purchase_orders`, `purchase_order_lines`, `purchase_order_revisions`
- `receipts` and `receipt_lines`
- `supplier_invoices`
- `procurement_documents`, `procurement_audit_events`, `document_sequences`
- `entry_currencies`

Integrity rules on PostgreSQL:

- CHECK constraints on statuses and quantities.
- A trigger makes the audit trail append-only.
- A partial unique index stops the same live supplier invoice number being used twice.

### 7.3 Rules the code enforces

These are not hardcoded per organization; settings and approval rules decide them.

- **Approval rules.** A rule has an amount band in the workspace currency, an optional department and method, and up to six ordered steps. A step is either "anyone with a permission" or a named member.
  - Without a rule, each document needs one approval from holders of its approve permission. Sourcing needs none.
  - Changing a rule never changes submissions already waiting.
- **Separation of duties.** These are refused unless `allow_self_approval` is on:
  - deciding what you submitted;
  - deciding two steps of the same round;
  - receiving an order you prepared.
- **Sourcing:**
  - criteria are fixed at issue;
  - sealed RFP and tender prices are hidden until the event closes;
  - late quotations are flagged;
  - a non-compliant quotation cannot be awarded;
  - anything other than the lowest compliant quotation, or fewer quotations than `min_quotations`, needs a written deviation reason.
- **Method limits.** Each method has a limit in base currency. A direct purchase above its limit is refused.
- **Orders:**
  - an approved order changes only by amendment, which snapshots the old revision and needs approval again;
  - an order cannot be cancelled once anything has been received;
  - closing an order short needs a reason.
- **Receiving:**
  - accepted quantity cannot exceed what remains;
  - rejected quantity needs a reason;
  - services are received the same way.
- **Invoices:**
  - duplicate numbers per supplier are refused (normalised);
  - the total invoiced cannot exceed the order;
  - an invoice matches when it is within accepted value plus tolerance.
  - "Paid" is recorded by hand and shown as not verified.
- **Finance (opt-in):** turning it on requires an expense account.
  - A matched invoice is sent once as `finance.bill.received` v1. The source id is `supplier_invoice:{id}`.
  - After that, voiding or marking paid in Procurement is refused, because Finance owns the bill.
- **Money.** Every amount keeps its document currency and the rate used. Comparisons and reports use the workspace base currency.

### 7.4 Verified

- **PostgreSQL 17:** `composer ci:check` passes, 36 tests and 572 assertions. It covers formatting, lint, types, Pint and PHPStan level 7.
- **SQLite:** the same 36 tests run, with 1 skipped (the trigger test).
- **What the tests cover:**
  - the full Scenario A lifecycle;
  - approval bands, steps and SoD;
  - tenant isolation, with 404s for another organization's records, forms and documents;
  - entitlement;
  - the Finance outbox: once, idempotent, and it locks the invoice;
  - suppliers, plans, amendments, limits, sealed bids and currencies.
- The app was registered with the local platform with no workspace entitled. It boots and answers 404 to non-workspace hosts.
- **Not verified:**
  - a signed-in browser session against the real local Central;
  - a Finance instance consuming the bill event end to end.

### 7.5 Known gaps (P1)

- Finance never reports payment back (P2).
- Projects references are free text (P3).
- No Inventory event (P5).
- Supplier documents have no expiry tracking.
- There are no email notifications. Approvers use the inbox and the overview.
