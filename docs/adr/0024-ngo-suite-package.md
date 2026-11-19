# ADR-0024: NGO Suite, a package of existing applications

**Status:** accepted, 2026-11-18. M1 (audit) and M2 (Central package and onboarding) built and tested locally. Nothing committed or deployed.

## Context

NGOs need projects with partners and donors, grants, the books, purchasing, and verified results. Each of these already lives in a Tatook application:

- **Projects** (`project-management`): projects, participants, partners, grant agreements and disbursement requests (ADR-0014, 0018, 0021), and partner reports (ADR-0015, 0022).
- **Finance** (`finance-app`): the ledger, payment requests from Projects (ADR-0017), dimensions and budgets.
- **M&E** (`mel-app` and `mel-mobile-app`): the field app, Tatook MDC, comes with the M&E entitlement (ADR-0019).
- **Procurement** (`procurement-app`, ADR-0023).
- **Payroll** (`payroll-app`, ADR-0010).

The question is how to sell them as one product without building a second backend.

## Decision

1. **A package, not an application.** NGO Suite is the existing ERP product `ngo`, kept under that code so no second code conflicts with it. It is renamed "NGO Suite".
   - **Required applications:** Projects, Finance, M&E and Procurement.
   - **Optional applications:** Payroll and HR.
   - Status is `pilot`.
   - There is no NGO backend, database, ledger or role engine. Each application keeps its own data and rules.
2. **Starting it.** A free trial of the product starts the trial of each required application, through `ManageEntitlements::startTrial`. It is started by the same registration intent as any product (`RegistrationIntents`, see below). It never removes access the organization already has: an application already held, paid or on trial, is left as it is.
3. **Selling it.** Selling uses one bundle plan, `ngo-suite`.
   - It gives one subscription with one item, and one entitlement per application.
   - When the plan ends, only that item's entitlements are revoked. Access granted another way, for example a standalone Finance subscription or a manual grant, stays.
   - The plan is a draft with no price until a price is approved. No unapproved price is activated.
4. **Payroll stays optional.** The payroll application is not registered in Central, and it has no project or grant cost allocation. Requiring it would block every NGO trial.
5. **Registration intents (Central).** A product, ERP product or plan chosen on the public site becomes a `registration_intents` row.
   - **Token:** opaque, 48 characters, stored only as a SHA-256 hash. It expires after 7 days.
   - **Claim:** the intent is claimed by the account that signs in, and only that account can see or complete it.
   - **Completion:** the account completes it for an organization it **owns**. Completion is serialized by a PostgreSQL advisory lock and is idempotent.
   - **Audit:** creation, claim and completion are recorded.
   - This replaces the old single session key (`RememberTrialChoice`) and the `trial-workspace` chooser. Each tab keeps its own intent.

## M1: readiness (verified against the code, 2026-11-18)

| Component | Existing | Missing | Action |
|---|---|---|---|
| Central bundles | ERP products, bundle plans (one item, an entitlement per application), trials per application | Starting a product as one package from the public site; choice lost through sign-up | **Done (M2):** registration intents, product trial, NGO composition |
| Projects | Projects, participants, partners, visibility (ADR-0016) | None for the package | Reused unchanged |
| Grants | Grant agreements and disbursement requests between organizations (ADR-0021) | Inter-organization transfer in Finance (ADR-0021 "Finance blocker"); amendments and closure | Not built. Needs a Finance grant-transfer contract. Sent and paid are recorded by hand and labelled as such. |
| Finance | Ledger, payment requests from Projects (ADR-0017), dimensions, budgets, project spending requests | Grant or donor as a posting dimension checked end to end | Audit in M4 before any change |
| Procurement | Standalone app (ADR-0023); Projects' own budget-line procurement (ADR-0020) | Link between the standalone app and project budget lines (ADR-0023 §5, P4) | Not built. Both coexist; the package grants the standalone app. |
| M&E and data collection | Projects to M&E link (ADR-0019); MDC included with M&E; collection-to-indicator mapping (built 2026-11-18, uncommitted) | Device validation of MDC | Device test pending |
| Payroll | Calculation, runs, payslips, journal to Finance | Registration in Central; project and grant cost allocation; confidentiality of allocations | Not built (M5). Optional in the package. |
| NGO dashboard | Projects' "what waits on each party" dashboard (Phase 5 M9) | Summary APIs in Finance, Procurement, M&E and Payroll; a Central overview | Not built (M3). No metric is shown without a source. |
| Reporting | Partner reports and obligations, M&E snapshot, CSV (ADR-0022) | Cross-application grant financial report, budget against actual across apps, PDF | Not built (M7) |

## Milestones

- **M1 audit:** done (this ADR).
- **M2 Central package:** done locally.
  - Product composition, catalog page `/erp/ngo`, draft bundle plan, registration intents.
  - Tests: `tests/Feature/Onboarding/*` (33 tests).
- **M3 workspace overview:** not started. It needs a read-only summary endpoint per application with an organization-bound token. Each endpoint applies the member's own permissions. Summaries load independently with short timeouts, and each one is shown as unavailable when its application is down. Nothing is cached across members.
- **M4 Finance and procurement audit:** not started. Covers grant attribution in Finance and procurement commitments against Projects' budget lines.
- **M5 Payroll allocation:** not started. Allocation percentages per employee and project, totalling 100%. Rounding remainders go to the largest share. Only allocation totals are shared with Projects.
- **M6 MDC device validation:** not started.
- **M7 cross-application reporting:** not started.
- **M8 end-to-end run:** not started.
- **M9 release:** not started.

## Consequences

- **Production listing:** deploying `erp-products.json` lists NGO Suite as Early access on the public site and opens its free trial. The trial covers only the applications that are open to trials in that environment. If any required application is closed to trials, the button falls back to the Projects trial.
- **Package scope:** until M3 to M7, the package is a commercial bundle with one sign-in. It is not a unified operating dashboard, and the public copy says only what exists.
- **Migration:** `registration_intents` is a new table, so rollback is a drop. That drop loses only pending choices; entitlements started through intents are ordinary entitlements and stay.
