# Petrol Station

Fuel operations for one Miles workspace, served at `{workspace}/petrol`. Sign-in, companies, branches, roles and billing stay on the platform. This app keeps the station's own database: tanks, pumps, shifts, sales, purchases and cash.

Code `petrol`, directory `petrol-app`. Dev port **8900**.

```bash
composer setup
composer dev          # http://127.0.0.1:8900
composer test         # Pint, PHPStan, Pest
```

Register it from `central-app` when that database is up:

```bash
php artisan platform:local-product petrol-app --url=http://127.0.0.1:8900
```

## Limits of this version

- A sale paid with more than one non-credit method cannot be voided here. Finance refunds one method. Cash plus credit can be voided; the refund is the cash portion.
- Supplier payments are operational. The bill is already in Finance, and Finance still shows the payable until someone pays it there.
- Offline sales and cash collections are a command outbox on this server (`POST /petrol/api/v1/outbox`). Two devices can both sync. The tank lock and the shift reconciliation catch the result. There is no separate stock database per device.
- User limits and billing stay in the platform. This app caps stations and attendants only when the plan includes `petrol.stations` or `petrol.attendants`.
