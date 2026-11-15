# Projects ⇄ Finance end-to-end run

This runs the real Central, Finance and Projects apps together, locally, to
check that a spending request travels from Projects into Finance, is
processed under Finance's rules, and comes back to Projects as an
authoritative outcome. The contract is `docs/finance/finance-projects-contract.md`
(§18).

```sh
php ops/e2e/finance-projects/run.php          # set up, run every scenario, stop
php ops/e2e/finance-projects/run.php --keep   # leave the three servers running
```

What it does:

1. **Databases.** It recreates `e2e_central`, `e2e_finance` and
   `e2e_projects` on the PostgreSQL server in `central-app/.env`. That role
   needs CREATEDB. It migrates all three.
2. **Central.** It imports the Finance and Projects catalogs and registers
   their clients. It then creates two organizations, acme and globex.
3. **Servers.** It starts the apps as PHP servers:
   * Central on :8911;
   * Finance on :8912;
   * Projects on :8913.

   Each runs with environment overrides (`lib.php`) that win over its `.env`.
4. **Entitlements.** It entitles both organizations to Finance and Projects.
   Central then provisions them by calling each product.
5. **Mode.** It switches acme to integrated through `FinanceModes`, so all
   the readiness checks apply.
6. **Scenarios.** It runs the scenarios (`run.php`) and writes
   `results.json` to `E2E_RUN_DIR` (default: `<temp>/tatook-e2e`).

The drivers (`central.php`, `finance.php`, `projects.php`) boot one app each.
They refuse to run unless that app is on its `e2e_*` database.

The run does not:

* touch the development databases;
* deploy anything;
* change any real organization.

Do not point it at staging or production.
