# ADR-0007: Restaurant as a separate application

- Status: Accepted (2026-10-25)
- Phase: Restaurant T0 onwards (`docs/erp/restaurant-roadmap.md`)

## Context

ADR-0003 put the ERP products in one workspace application (`pharmacy-app`) with shared
Inventory, Procurement and Sales modules, and Retail joined it as a hosted industry module. The
restaurant was first built the same way (served at `{workspace}/restaurant` by `pharmacy-app`).
Doing so changed the shared engine for every industry: items that keep no stock, sale lines
without a batch, reports that tolerate them, and a hosted application's pages inside the
pharmacy's shell. A restaurant's day is also unlike a shop's: tables, kitchen tickets and bills
come first, and most of what it sells (dishes) is made to order rather than taken off a shelf.

Options considered:

1. **A separate `restaurant-app`** (from `templates/product-app`), one registry application
   `restaurant`, with its own small engine: the menu, drinks stock, tables, orders, kitchen
   tickets, tills and bills.
2. A hosted industry module of `pharmacy-app`, as Retail is.

## Decision

Option 1.

- A restaurant sells dishes, which take no stock until recipes (T3); the shared engine is built
  around batches, expiry and dispensing, and every change for the restaurant risked the pharmacy
  and the shop.
- Its own database, deployment and php-fpm pool: a busy service never slows the pharmacy, and a
  fault in one cannot reach the other's data.
- Sellable on its own (with Finance) without running the workspace application.

## Consequences

- Finance hears of restaurant business through the same contracts as the shop:
  `finance.invoice.issued` and `finance.payment.received` per bill (each line with its cost),
  `finance.invoice.voided` with the refund, `finance.stock.adjusted` for drinks found, counted or
  written off, and `finance.cash.counted` for a till over or short.
- Branches stay owned by central-app `Structure`; the restaurant stores their ids and keeps the
  member's choice in its session, as the pharmacy does (`App\Support\Branches`).
- Drinks are counted per branch at an average cost, not in batches: nothing a restaurant sells
  expires on the shelf in a way the pilot needs to track. Ingredients and recipes (T3) build on
  the same stock levels.
- Purchasing for the restaurant is not shared with `pharmacy-app`; until it has its own (T3),
  drinks come in as opening stock or counts.
- `pharmacy-app` no longer hosts the restaurant; Retail stays hosted under ADR-0003.
- Option 2 is not to be revisited without a new ADR.
