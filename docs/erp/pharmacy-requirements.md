# Miles Pharmacy ERP — requirements and gap analysis

Against `MASTER-PLAN.md` §7, from reading `pharmacy-app` and `finance-app` (2026-10-16).

| Requirement | State | Evidence / gap |
| --- | --- | --- |
| Medicine details: generic/brand, strength, form, unit, barcode, Rx-only, controlled | ✅ | `medicines` table |
| Packaging (pack ↔ unit conversions), storage requirements, category | ✅ | packs bought (3.3) and sold at the counter with their own price; item category and storage condition (Phase 3.9) |
| Batch number, expiry, unit cost per branch | ✅ | `batches` |
| FEFO allocation, expired stock blocked at sale | ✅ | `Stock` orders by `expires_on`, excludes expired |
| Quarantine / recall status | ✅ | batch status blocks sale; quarantine releasable, recall final (Phase 3.7) |
| Adjustments with reasons, disposal | ✅ | `AdjustmentReason`; damaged/expired leave through a disposal approved by a second person (Phase 3.7) |
| Stock by warehouse/location | ❌ | branch only |
| Append-only movements, non-negative stock | ✅ | trigger + CHECK |
| Suppliers, PO, goods receipt → stock + Finance bill | ✅ | `ReceiveGoods` (outbox `finance.bill.received`) |
| Supplier returns → Finance credit | ✅ | `ReturnGoods` |
| POS: search, barcode entry, cart, cash/configured methods | ✅ | `sales/create.tsx`, `PaymentMethod` |
| Discounts (validated) | ✅ | percent per line, `pharmacy.sales.discount`, capped (Phase 3.4) |
| Printable receipts | ✅ | `sales/{sale}/receipt`, 80 mm (Phase 3.4) |
| Sales returns (partial) | ✅ | `ReturnSale`, Finance `finance.sales_return.issued` |
| Cashier sessions / till reconciliation | ✅ | `tills`, count at closing; over/short not posted to Finance (Phase 3.5) |
| Idempotent sale submission | ✅ | `submission_id` per basket (Phase 3.4) |
| Accounting posting (revenue, payment, COGS, voids) | ✅ | events to Finance |
| Prescriptions, pharmacist verification, partial dispensing | ✅ | |
| Controlled drugs register | ✅ | |
| Reports: stock, near-expiry, sales, value | 🟠 | branch report page |
| Reports: profitability, purchase history, supplier balances, cashier reconciliation | ✅ | `reports/profitability`, `reports/purchases`, `reports/cashiers` (Phase 3.8); supplier balances live in Finance payables |
| SUNMI device integration | ❌ | none in the repo |
| Offline POS | ❌ | correctly deferred (Phase 11) |

## Compliance items needing external verification

Country rules (South Sudan first; others per market) for: prescription retention period,
controlled-substance register format and reporting, pharmacist-on-duty requirements, recall
notification duties, receipt/tax invoice content. None of these are asserted by the product today.

## Pilot exit criteria (summary; full list in `implementation-roadmap.md` Phase 8/9)

Idempotent sales, cashier sessions with reconciliation, quarantine/recall blocking, receipts,
partial returns, financial periods in Finance, reports reconciled to Finance on a seeded month.
