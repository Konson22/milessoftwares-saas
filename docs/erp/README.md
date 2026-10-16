# ERP documentation

The ERP transformation (`MASTER-PLAN.md`). Existing platform docs are reused rather than copied.

| Required document | Where |
| --- | --- |
| architecture-audit | [architecture-audit.md](architecture-audit.md) (inventory, versions, capability audit, reuse matrix, risk register) |
| target-architecture | [../architecture.md](../architecture.md), [ADR-0003](../adr/0003-erp-workspace-application.md), [ADR-0004](../adr/0004-companies-as-organizations.md) |
| product-catalog | [product-catalog.md](product-catalog.md) |
| module-registry | `applications` registry: [../architecture.md](../architecture.md) §2–3, [../integration.md](../integration.md) |
| domain-ownership | [domain-ownership.md](domain-ownership.md) |
| database-schema | [../architecture.md](../architecture.md) §3; product schema in [product-catalog.md](product-catalog.md) |
| tenant-isolation | [../architecture.md](../architecture.md) §3, §5; [../security-review.md](../security-review.md) |
| pharmacy-requirements | [pharmacy-requirements.md](pharmacy-requirements.md) |
| security-model | [../security-review.md](../security-review.md), [../architecture.md](../architecture.md) §4 |
| migration-plan | [migration-plan.md](migration-plan.md) |
| implementation-roadmap | [implementation-roadmap.md](implementation-roadmap.md) |
| test-plan | acceptance criteria per phase in [implementation-roadmap.md](implementation-roadmap.md); results in [../PROGRESS.md](../PROGRESS.md) and [implementation-status.md](implementation-status.md) |
| implementation-status | [implementation-status.md](implementation-status.md) |
| deployment-runbook | [../runbooks/deploy.md](../runbooks/deploy.md), [../production-checklist.md](../production-checklist.md) |
