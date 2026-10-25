# Restaurant (`restaurant-app`)

Table service for restaurants and cafés, served at `{workspace}/restaurant`. One registry
application, `restaurant` ([ADR-0007](../docs/adr/0007-restaurant-application.md), phases in
[`docs/erp/restaurant-roadmap.md`](../docs/erp/restaurant-roadmap.md)). Built from
`templates/product-app` and connected through
[`miles/platform-client`](../packages/platform-client/README.md).

## What is here (T0–T1)

- **Branches** (`App\Support\Branches`): tables, orders, bills, tills and stock belong to the
  branch the member works in, chosen once per session (`branch` middleware) among the branches
  where they hold a restaurant role. `branch.can:<permission>` checks a permission in that
  branch: a role for another branch does not count.
- **Menu** (`MenuItem`): dishes (made to order, no stock) and drinks (counted at each branch),
  by section, at one price for the whole organization.
- **Floor and orders** (`App\Actions\Orders`): one open order per table (a second waiter joins
  it), takeaways, lines with a note for the kitchen, sent on kitchen tickets (`KT-000001`). A
  line not yet sent is removed; one the kitchen has is voided with a reason and never billed.
- **Kitchen screen**: tickets oldest first, marked ready by the kitchen and served by the waiter.
- **Bills** (`App\Actions\Bills`): paid at today's menu prices into the cashier's open till
  (`B-000001`), voided whole with the money given back from the voider's till. Each bill takes
  its drinks from stock at their average cost.
- **Tills** (`App\Actions\Tills`): a shift opened with a float and closed with a count; a count
  that differs needs a note.
- **Drinks stock** (`App\Actions\Stock`): added at cost, counted or written off at the average
  cost; never below zero.
- **Finance**: bills (`finance.invoice.issued`, `finance.payment.received`), voids
  (`finance.invoice.voided`), stock changes (`finance.stock.adjusted`) and till differences
  (`finance.cash.counted`) go out through the platform outbox, in the same transaction.

## Develop

```bash
createdb restaurant              # owner: your local DB user
composer setup                   # install, .env, key, migrate, build
cd ../central-app && php artisan platform:local-product restaurant-app --url=http://127.0.0.1:8700 --entitle=<workspace-slug>
cd ../restaurant-app && composer dev   # :8700, queue, Vite
composer ci:check                # format, lint, types, Pint, PHPStan, Pest
```

Open `http://<workspace-slug>.milesoftwares.localhost/restaurant` (proxy entry in
`ops/local-dev/apache-dev.conf`). The routes for the front end are generated from the
configured application, so `.env` (`PLATFORM_APPLICATION=restaurant`) must exist before a build.
Production: `ops/nginx/products/restaurant.conf`, `ops/php-fpm/restaurant.conf`, and `restaurant`
in `PRODUCTS` for `ops/deploy/deploy.sh` once the server has the `restaurant` database and
`.env`.
