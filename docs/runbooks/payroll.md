# Payroll: go-live and operations

Payroll is `payroll-app` at `{workspace}/payroll` (ADR-0010). It deploys like any product
(`docs/runbooks/deploy.md`, Products); this page is what is specific to it.

## Before the first deploy (once, on the server)

1. Database, owned by `miles_owner`:

    ```bash
    sudo -u postgres psql -v db_name=payroll -f ops/postgres/product-database.sql
    ```

2. `/srv/miles/shared/products/payroll/.env` (0600 `miles:miles`) from `payroll-app/.env.example`:
   `APP_ENV=production`, `APP_DEBUG=false`, a new `APP_KEY`, `DB_DATABASE=payroll`,
   `DB_USERNAME=miles_app`, the `PLATFORM_*` values, and `PLATFORM_FINANCE_EVENTS_URL`
   (Finance's `/finance/events`) so approved runs reach the books.
   **`APP_KEY` encrypts each person's bank and mobile money details**: keep it with the database
   backups. A lost key means re-entering every account.
3. Storage: `/srv/miles/shared/products/payroll/storage/{app,framework/{cache,sessions,views},logs}`.
4. Register the application in admin → Applications (code `payroll`) for its sign-in and service
   clients and webhook secret, then copy those values into its `.env`.
5. HR and every product that sends hours: `PLATFORM_PAYROLL_EVENTS_URL=https://tatook.com/payroll/events`
   in their `.env`, then `artisan optimize` and a php-fpm reload.
6. nginx: `ops/nginx/products/payroll.conf` into `/etc/nginx/miles-products/`, `nginx -t`, reload.
   php-fpm: `ops/php-fpm/payroll.conf` into the pool directory, reload.
7. Make sure `payroll` is in `PRODUCTS` for the deploy. Until step 4 is done, the `miles-deploy`
   wrapper leaves it out ("not registered yet, left out: payroll").

## After the first deploy

- `systemctl enable --now miles-product-queue@payroll miles-product-scheduler@payroll.timer`
  (the scheduler delivers the outbox to Finance every minute).
- The deploy imports `payroll-app/platform/catalog.json` (1.1.0). Give someone the
  **Payroll administrator** role, and a second person **Payroll approver**: whoever prepared
  a run can never approve it.
- Check, as the runtime role, that the database protections are in place:

    ```sql
    -- Both must fail.
    UPDATE payroll_audit_events SET action = action;          -- "append-only"
    UPDATE payslips SET net_minor = net_minor
      WHERE payroll_run_id IN (SELECT id FROM payroll_runs WHERE status <> 'draft');  -- "never change"
    ```

  (No rows yet: both statements succeed trivially. Check again after the first approved run.)

## Roles (catalog 1.1.0)

| Role | Can |
| --- | --- |
| Payroll clerk | Keep the list of people (no pay) |
| Payroll officer | Pay, allowances, payment details; prepare runs and add one-off adjustments |
| Payroll reviewer | Mark a prepared run reviewed (optional second check); audit trail |
| Payroll approver | Review, approve, record payments, reverse an unpaid run; audit trail |
| Payroll administrator | Everything, including tax and social security rules |
| Payroll auditor | Read everything, including the audit trail; changes nothing |

## A run's life

```
draft ──review (optional)──▶ reviewed ──approve──▶ approved ──pay some──▶ part_paid ──pay rest──▶ paid
  ▲   recalculate / adjust     │                      │
  └────────────────────────────┘                      └──reverse (nothing paid yet)──▶ reversed
```

- **Approve** sends `finance.payroll.posted` (run totals only). Whoever last prepared,
  recalculated or adjusted the run cannot approve it.
- **Pay** records a batch (`PR-00001-P1`, `-P2`, …) for all unpaid payslips or the ticked ones,
  and sends one `finance.payroll.paid` per batch with that `payment_reference`. Download
  **Payment instructions** (account details and net pay) for the bank or cashier first.
- **Reverse** (approved, nothing paid): Finance mirrors the journal
  (`finance.payroll.reversed`), the payslips stay marked reversed, and the month can be
  prepared again with a new run number.
- A run keeps the currency it was prepared in. Finance refuses events in a currency other than
  its books', so change a workspace's currency only between runs, with Finance changed to match.

## When something is stuck

- Events to Finance wait in Payroll's `platform_outbox` (status `pending`/`failed`) and are retried
  by the scheduler. Finance answers 503 "retry later" for a reversal that arrives before its run's
  journal; that clears itself on the next delivery.
- `currency_mismatch` from Finance: the run's currency differs from Finance's books. Do not edit the
  run; reverse it (if unpaid) and prepare again once the currencies agree.
- A payslip paid by mistake cannot be "unpaid" in Payroll: correct it in Finance with a journal and
  note it in the next run's adjustments if pay must be recovered.
