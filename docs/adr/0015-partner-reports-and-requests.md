# ADR-0015: Partner reports, project requests and their reviews

- Status: **Accepted** (2026-11-15). Phase 2 of the Tatook Projects collaboration brief, on top of
  ADR-0014 (participants, capabilities, representatives).
- App: `project-management`. Finance, Payroll, Procurement and M&E workflows are reused, not
  changed.

## 1. What exists (inspection)

| Brief item | Exists | Verdict |
|---|---|---|
| Reports to funders | `grant_reports` (due → submitted → revision requested → accepted), documents, messages, donor portal; owner-side bookkeeping of what is owed to a funder, which may not be on Tatook | **Kept as is.** Partner reports are a different thing: submitted *by* one project party *to* another, with content and versions |
| Change requests | `change_requests` (internal: raise → approve / reject / withdraw; approval moves the due date and opens a draft budget version) | **Kept** for internal changes. Its effects are reused: a budget revision opens a draft through `Budgets::revise`; a new end date is applied as change requests apply it |
| Budget versions | draft → submitted → approved (`finance.budgets.approve`) → superseded | **The authoritative route** for any budget revision. A request never edits a budget |
| Funding schedule and receipts | `grant_disbursements` (scheduled → received; receipt sends `finance.grant.received`) | A funding request **links** to a disbursement. It never creates or receives one |
| Payments, advances, expense approvals | `expenses` with bands and SoD, `staff_advances`, Finance events | Not duplicated. A payment request is an expense or a Finance payment request, never a project request |
| Procurement requisitions | `purchase_requests` and their approvals | Not duplicated. The requests page links to Procurement |
| M&E verification | `mel_verified_results` (verified, published, superseded) | Reports **snapshot** verified results of units linked to the project, read-only |
| Participants, capabilities, representatives | ADR-0014 | The routing and visibility foundation |
| Attachments | `documents` (organization-scoped library), `grant_report_documents` | One small attachment table shared by reports and requests, on the private disk, because files cross organizations |
| Notifications, reminders, audit | `Alerts::tell`, `Reminders::run` (daily, deduplicated keys), `audit_events` | Reused |

## 2. Parties and routing

A **party** on a project is its owner or one of its participants (ADR-0014). Every report and
request names a submitting party and a reviewing party. The owner is represented as no
participant.

**Default route** (`App\Collaboration\Routing`):
- A participant submits to the first live participation above it that holds the review capability.
  If there is none, it submits to the owner. A sub-partner therefore goes to its lead, and a direct
  implementer goes to the owner.
- The owner submits either internally (Scenario A) or to a participant that holds the review
  capability, such as a donor taking part (Scenario B, owner implementer → donor).
- There is never an intermediary that does not exist.

Reporting obligations set the route explicitly, chosen from the same candidates
(`projects.reporting.manage`). A reviewing participant may **escalate** to the party above it.

## 3. Authorization

New catalog permissions (`projects` **3.2.0**):

| Permission | admin | manager | member |
|---|---|---|---|
| `projects.reports.prepare` | ✓ | ✓ | ✓ |
| `projects.reports.review` | ✓ | ✓ | |
| `projects.reports.publish` | ✓ | ✓ | |
| `projects.reporting.manage` | ✓ | ✓ | |
| `projects.requests.raise` | ✓ | ✓ | ✓ |
| `projects.requests.review` | ✓ | ✓ | |

- **Acting for the owner:** the member holds the permission in the owning organization.
- **Acting for a participant:** the member holds the permission in their own organization **and**
  works on the project for it (representative, or `projects.partners.manage`) **and** the
  participation's effective capabilities include the matching one: `reports.submit`,
  `reports.review`, `reports.publish`, `requests.create` or `requests.review`. These capabilities
  are now effective, and the owner-side permissions above are what imply them (ADR-0014 §3).
- **Separation of duties:** nobody decides a report they submitted or a request they raised. A
  party never reviews itself. Request types never carry thresholds in code.

**Who sees a report:**
- the submitting party, always;
- the reviewing party, once it is submitted;
- once **published**, the owner and every participant whose capabilities include
  `donor_reports.view`.

Approval does not publish. A sub-partner's report is not seen by the donor unless someone
publishes it, or the lead's consolidated report carries it.

**Who sees a request:** the raising party and the reviewing party (and anyone it was escalated
from).

## 4. Reports

- `report_obligations`:
  - project, responsible party, reviewing party, category;
  - frequency: monthly, quarterly, annual, once or custom;
  - first period start, last period end, days allowed after a period ends;
  - whether approval is followed by publication (a hint shown to the reviewer);
  - status active / ended.

  Creating one generates its period instances, unique on (obligation, period start), so nothing
  is duplicated.
- `project_reports`:
  - one per obligation period, or an ad-hoc one;
  - category, period, due date, parties;
  - status: draft → submitted → under review → approved → published, plus changes requested,
    rejected and withdrawn;
  - current version.
- `project_report_versions`: numbered **structured content** (JSON, validated per section).

  | Section | Fields |
  |---|---|
  | Implementation | activities planned and completed, milestones, deliverables, delays, challenges, risks, corrective actions |
  | Financial | figures, each with a source |
  | M&E | snapshot |
  | Narrative | achievements, challenges, lessons, next period |

  Financial sources:
  - **Recorded in Tatook Projects**: approved budget, recorded spending and variance. These are
    included only for a party allowed to see the budget, and labelled "not verified by Finance".
  - **Reported by the organization**: unverified.

  The M&E snapshot holds verified results of M&E units linked to the project for periods that
  overlap the report's.

  A version is frozen once submitted. Changes after that make a new version, and earlier ones stay.
- `project_report_reviews`: append-only decisions and comments, with the version they concern.
- `project_report_sources`: the approved reports a consolidated report is built from. Source
  reports are kept as they are. Nothing is summed: each source is listed with its figures, and
  missing ones are named.

## 5. Requests

- `project_requests`:
  - reference `REQ-n` per project, type, parties, title, justification;
  - **type-specific details**, validated per type:

    | Type | Details |
    |---|---|
    | Funding | amount, currency, funding agreement reference, budget categories |
    | Budget revision | changes by category: current → proposed, reason |
    | Activity change | what changes and why |
    | Timeline extension | current and proposed end date |
    | Information | what is asked |

  - status: draft → submitted → under review → approved / rejected, plus changes requested and
    cancelled;
  - links to what followed: `budget_version_id`, `grant_disbursement_id`.
- `project_request_decisions`: append-only. A submission keeps a snapshot of the details, so
  what was approved is what was asked.
- **Effects are never automatic, and always through the owner's authoritative workflows:**
  - A budget revision, once approved, lets the owner *start the budget revision*: a draft
    version through `Budgets::revise`, then the usual submit and approve.
  - A timeline extension lets the owner *apply the new date*, recorded as change requests do.
  - A funding request lets the owner *link a scheduled disbursement*. The request shows "approved,
    not paid" until that disbursement is received. An approved funding request is never counted
    as funds received.

## 6. Deferred

Advanced templates, donor-specific formats, financial reconciliation with Finance (needs the
Finance → Projects inbox, ADR-0013 §7), amount-band routing for requests, a cross-project
reporting calendar beyond the per-project lists, analytics, and migrating `grant_reports` and
`change_requests` into these tables.

## 7. As built (2026-11-15)

Code is in `project-management/app/Collaboration`; nothing in Finance, Payroll, Procurement or M&E
changed.

- **Who acts:** `Acting` resolves, on every request, the owner side (`projects/{project}/…`, the
  member's organization owns the project) or a participant side (`shared-projects/{participant}/…`,
  `ParticipantAccess::canOpen` plus an active chain). `Acting::may(permission, capability)` needs the
  member's permission and, for a participant, the participation's effective capability. Suspending
  or revoking a participation, or removing a representative, cuts access on the next request.
- **Routing:** `Routing::defaultReviewer` walks up the parents to the first active participation
  holding the review capability, otherwise the owner. `candidates()` offers the owner and active
  reviewers that are neither the submitter nor under it. A reviewer that can no longer review when
  something is submitted is replaced by the current default route, and the audit records
  `rerouted`.
- **Reports** (`Reports`, `ReportContent`, `ReportPeriods`):
  - Obligations are owner-side only (`projects.reporting.manage`). Their periods are generated
    from the start date each time, so a 31st does not drift; there are at most 120 periods, and
    each one is checked for existence before it is created. Ending an obligation withdraws only the
    unsubmitted drafts of periods that have not started yet.
  - Submitting freezes the version and adds a `system` section:
    - recorded budget figures, only if the submitting member may see the budget, labelled "not
      verified by Finance";
    - verified, non-superseded M&E results of units linked to the project for overlapping
      periods, listed and not added up.
    Pages hide `system.financial` from viewers who may not see the budget.
  - Escalation applies to **requests only**; a report goes back to its submitter or is approved
    or rejected.
  - Publishing is open to the reviewer, or to the owner for a report it can see.
  - The owner lists every report on its project (metadata only, to follow deadlines). It opens
    only reports it is a party to, and published ones.
- **Requests** (`Requests`, `RequestDetails`):
  - A request's details are validated per type.
  - Each submission keeps a snapshot.
  - The decider is never the raiser or the last submitter.
  - Approval changes nothing by itself. Owner follow-ups:
    - `startBudgetRevision` opens a draft through `Budgets::revise` (needs `projects.manage` and
      `projects.finance.view`);
    - `applyTimeline` sets the project's end date, with the previous date in the audit and the
      activity log (needs `projects.changes.approve`);
    - `linkDisbursement` links a payment on a grant allocated to the project (needs
      `grants.manage`), once per payment.
  - Funding is shown in four states: "approved, not paid", "scheduled", "received" (read from the
    disbursement), and not approved.
- **Notifications:** `Notify::party` uses `Alerts::tell` in the receiving party's workspace. On a
  participant side it reaches only members who hold the permission and are representatives or
  collaboration managers. Submissions carry dedupe keys. `Reminders::run` adds
  `Reports::remind`, which sends reminders for reports due within 7 days or overdue, keyed
  `partner-report-due|overdue:{id}:{due}`, without email.
- **Attachments:** `collaboration_attachments` on the private disk.
  - Files are added to the open version or to a request still being written.
  - A draft's files are the submitter's own until it is submitted.
  - Downloads are sent with `Cache-Control: no-store`.
- **Audit:** every step is recorded for the owner and, when another organization acts, for that
  organization too.
- **Catalog 3.2.0:**
  - the six permissions in §3;
  - capabilities `reports.submit`, `reports.review`, `reports.publish`, `requests.create`,
    `requests.review` and `donor_reports.view` (renamed "View published reports", implied by
    `projects.view`) are now effective;
  - the suggested presets give a lead `requests.review` and a sub-implementer `requests.create`.
- **UI:**
  - "Reports" and "Requests" tabs on a project;
  - Reports and Requests buttons on a shared project;
  - the pages are `portal/collaboration/{reports,report,requests,request}`, shared by both sides.
- **Tests:** `tests/Feature/PartnerReportsTest.php`. The Phase 1 helpers moved to
  `tests/Pest.php`.

**Deploy:** back up the database, run `php artisan migrate`, then
`php artisan platform:catalog-reassign projects` (catalog 3.2.0), then the usual build.
