# Schema migration and compatibility plan

Rules: additive first; no destructive production migration without explicit owner approval; every
migration has a tested `down()`; backfills run as idempotent commands, not inside migrations.

| Phase | Change | Kind | Compatibility |
| --- | --- | --- | --- |
| 1.1 (done) | `erp_products`, `erp_product_applications` | new tables, CHECKs, FKs (application delete restricted) | no existing table touched; rollback drops both |
| 1.2 | `plans.erp_product_id` nullable FK | additive column | existing plans unaffected |
| 1.3 | entitlement grace / read-only (`entitlements.read_only_until` or plan-level policy) | additive | `current()` semantics unchanged; new scope for read-only |
| 3 | shared master data in the ERP workspace DB (units, customers; suppliers moved under `Procurement`) | new tables + backfill command | pharmacy tables kept until consumers are switched |
| 4 | finance periods (`fiscal_periods`, closed-period guard) | new tables + trigger | existing journals fall into open periods |
| 5 | composite `(organization_id, id)` FKs on product line tables; warehouses/locations | `ADD COLUMN` + backfill + `VALIDATE CONSTRAINT` | run in two deploys: add NOT VALID, validate later |
| 7 | `sales.idempotency_key` unique per org; `cashier_sessions` | additive | old sales keep NULL key |
| 8 | batch `status` (available/quarantined/recalled) | additive, default `available` | existing batches remain sellable |

Rollback procedure: `php artisan migrate:rollback --step=1` for the last additive migration;
data-moving steps are reversed by their command's `--revert` mode, never by dropping source tables.
Backups before every production migration per `docs/runbooks/backup-restore.md`.
