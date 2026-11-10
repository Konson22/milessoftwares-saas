# Phase 16: procurement

Buying for a project, from the request to payment, without a second set of books.

    Request -> approved -> quotes -> one chosen -> purchase order
            -> finance approves the spending -> goods received -> invoice -> paid

## The decision that shapes it

A purchase order's commitment is an ordinary expense. Raising an order calls
`Spending::request`, and from there the existing rules apply: approval bands,
several approvers, the budget and grant checks at each approval, separation of
duties, the Approvals queue, and the journal when paid. The order holds no status
of its own for the money: it reads its expense, so the two can't disagree, and a
budget or grant can't count the same purchase twice.

This differs in one way from `docs/platform-assessment-and-roadmap.md` §8: finance
approves the spending after the order is raised, rather than the order being
approved separately. The roadmap's nine stages were never spelled out; these are
the stages in `docs/phase-6b-finance-report.md`, plus the invoice and payment.

Suppliers are the ones in the books (`App\Accounting\Models\Supplier`), and a new
one can be added while entering a quote.

## What was built

- **Tables:** `purchase_requests`, `purchase_request_lines`, `purchase_quotes`,
  `purchase_orders`, `goods_receipts`, `goods_receipt_lines`.
- **Rules:** `App\Actions\Procurement`, every change audited.
- **Numbers:** `PR-000001` and `PO-000001`, gap-free per organization.
- **Quantities:** exact to two decimals (`App\Support\Quantity`).
- **Screens:** `portal/procurement/index`, `create`, `show`, and a menu item.
- **Alerts:** a submitted request to approvers, a decision to the requester. Finance
  gets the existing spending alert.

| Rule                                   | Where                                                                   |
| -------------------------------------- | ----------------------------------------------------------------------- |
| The requester can't decide their own   | `Procurement::decide`, owners included                                  |
| Fewer than 3 quotes needs a reason     | `Procurement::choose`                                                   |
| Passing over the lowest needs a reason | `Procurement::choose`                                                   |
| An expired quote can't be chosen       | `Procurement::choose` and `raiseOrder`                                  |
| The order raiser can't approve or pay  | The existing `Spending` rule                                            |
| Budget and grant limits                | `Spending::approve`, at finance approval                                |
| Goods only after finance approval      | `Procurement::receive`                                                  |
| No more received than ordered          | `Procurement::receive`                                                  |
| No payment before goods and invoice    | `Spending::pay` calls `Procurement::requirePayable`                     |
| A rejected order can be raised again   | `PurchaseRequest::isBuying`; a second order is refused while one stands |

## Permissions

Four new ones, synced by `php artisan access:sync`.

| Permission            | Who has it by default                                                            |
| --------------------- | -------------------------------------------------------------------------------- |
| `procurement.request` | Admin, Project Manager, Team Member, Procurement Officer (new)                   |
| `procurement.view`    | Admin, Project Manager, Finance Controller, Finance Officer, Procurement Officer |
| `procurement.approve` | Admin, Project Manager, Finance Controller                                       |
| `procurement.manage`  | Admin, Procurement Officer                                                       |

Someone with only `procurement.request` sees their own requests, not everyone's:
supplier prices shouldn't reach people who can't see budgets. The budget balance on
a request shows only with `projects.finance.view`.

## Tested

- `tests/Feature/ProcurementTest.php`, over HTTP: the whole path to payment with one
  expense throughout; estimates; each separation rule; the reasons for choosing; expired
  quotes; private documents reachable only through their own request; the budget limit
  deciding between two orders; grant charging; part deliveries and over-receiving;
  payment refused without goods or invoice; ordering again after a rejection; each
  permission; another organization gets 404.
- Full suite on SQLite and on MariaDB 10.4.
- Migration up, down and up again on SQLite.
- **In a real browser (headless Chrome, on seeded demo data):** one purchase from the
  create form to "ready for finance to pay", including several item rows, a quote with
  a file, the reason prompt appearing only for dearer quotes, the order in the Approvals
  queue, finance approving it there, an over-delivery refused, a delivery and an invoice.
  No JavaScript errors. A first run also showed finance refusing an order with no money
  left in its budget category.

## Not tested

- Clicking Pay in the browser. Payment and its refusals are tested over HTTP only.
- Real email. Mail was logged, not delivered.
- Rejecting a request in the browser. The rule is tested over HTTP only.

## Limits

- **One payment per order**, because an expense has one payment. A part delivery can't
  be part paid, and an order short-delivered for good can't be closed: it needs
  cancelling and raising again for what arrived.
- **A quote is one total**, with no prices per line. The invoice amount isn't compared
  with the order: only its number and date are recorded, so that match is left to
  finance.
- **The order is not sent or printed.** There's no PDF for the supplier yet.
- **The same person can raise an order and receive the goods.** Small teams often need
  that, but some donors won't accept it.
- **Three quotes is a fixed rule,** not set per organization, grant or amount. It's a
  written reason, not a hard stop.
- **Requests aren't in the Approvals page.** Approvers get an alert and a status filter
  on the Procurement page. Finance's part is in Approvals.
- **Access is organization-wide,** not limited to projects someone works on.
- **Receipts can't be corrected.** A wrong delivery stays on record.
- **Supplier records aren't vetted:** no registration, tax details or blacklist.
- **The demo seeder has no purchases,** so Procurement starts empty.
