# Logistics

Logistics ERP for transport, freight, courier and distribution companies: Laravel 13 + Inertia +
React, connected to the platform through
[`miles/platform-client`](../packages/platform-client/README.md). Served at
`{workspace}/logistics`.

## Features

- **Customers**: who shipments are carried for and invoiced to, with what each one owes.
- **Shipments**: booked (`SH-00001`), loaded onto a trip, in transit, then delivered with proof
  (who received it, when, a note) or not delivered (booked again when the trip ends). Every step
  is kept in the shipment's tracking history, along with updates from the road.
- **Fleet**: vehicles (status, capacity, odometer), their maintenance and its cost; drivers and
  their licences. Trips refuse vehicles in maintenance or retired, inactive drivers and expired
  licences.
- **Trips** (`TR-00001`): a vehicle, a driver and a day; shipments loaded, dispatched with the
  odometer reading, completed once each shipment is delivered or not delivered. Costs (fuel,
  tolls, allowances...) against what the delivered shipments earned give each trip its margin.
- **Billing**: invoices (`INV-00001`) for a customer's delivered shipments, each shipment once;
  payments (`RCT-00001`) in part or whole; unpaid invoices voided, freeing their shipments.
  Finance hears of each through the outbox: `finance.invoice.issued`,
  `finance.payment.received`, `finance.invoice.voided`.

State changes live in `app/Actions` (`Shipments`, `Trips`, `Invoices`), each inside a
transaction with the rows locked. Amounts are integers in the currency's minor unit
(`App\Support\Money`). Everything is organization-wide (no branches).

## Roles

Defined in `platform/catalog.json`: Logistics Manager (everything), Dispatcher (shipments, trips,
deliveries), Driver (deliveries and tracking), Fleet Officer (vehicles, drivers, maintenance),
Billing Clerk (invoices and payments). Voiding invoices is its own permission.

## Develop

```bash
composer setup        # install, .env, key, migrate, build
composer dev          # php artisan serve on :8800, queue, Vite
composer ci:check     # format, lint, types, Pint, PHPStan, Pest
```

Register with the local platform:
`cd ../central-app && php artisan platform:local-product logistics-app --url=http://127.0.0.1:8800 --entitle=<workspace-slug>`.
