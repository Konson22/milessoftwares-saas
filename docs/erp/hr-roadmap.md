# HR & Payroll roadmap

`hr-app`, served at `{workspace}/hr`, one registry application `hr`
([ADR-0005](../adr/0005-hr-and-payroll-application.md)). Payroll is its own module since
2026-11-09: `payroll-app` ([ADR-0008](../adr/0008-payroll-separate-application.md)). Every phase: tests, Pint, PHPStan,
`vp check`, `tsc` and build green; migrations rolled back and forward on PostgreSQL; results in
`implementation-status.md`. The application stays `planned` (shown as coming soon, not sold) until
the pilot list below is met.

| Phase | Scope | Acceptance |
| --- | --- | --- |
| H0 Foundation ✅ | platform API for departments and positions; `hr-app` from the template; catalog (roles, sensitivity); ops entries | structure lists never show another organization's rows; platform-client caches and fakes them |
| H1 Staff records ✅ | profiles (work details), employee numbers, managers, employment history (hired, transfer, promotion, change, left), contracts, leaving, encrypted personal details, HR audit log | branch- and department-scoped members see only their staff; personal details unreadable in the database and in the log; nothing changes for someone who has left |
| H2 Documents ✅ | `hr_documents` private disk, upload (type and size allowlist), signed downloads, expiry dates and reminders (contracts, permits, licences), backups include the files | no document reachable without a signed, short-lived URL; expiring documents listed on the home page |
| H3 Leave ✅ | leave types and entitlements per year, balances, requests, approval by the line manager (SoD: not one's own), public holidays | balance never negative unless the type allows it; approving one's own request refused |
| H4 Attendance | `hr.employee.joined` from other products (audience `hr`); timesheets now go to Payroll (`payroll.timesheet.submitted`, audience `payroll`) | duplicate event is a no-op; hours land against the right employee |
| H5 Payroll → `payroll-app` ✅ | moved out of HR ([ADR-0008](../adr/0008-payroll-separate-application.md)): pay, allowances and deductions, statutory rules (South Sudan preset), runs prepare → approve → paid, payslips; HR sends `payroll.employee.joined` / `.left` | preparer cannot approve; an approved run never changes (PostgreSQL trigger); payslip totals equal the run |
| H6 Finance ✅ (Payroll) | `finance.payroll.posted` (one summarised journal per run) and `finance.payroll.paid`; Finance system accounts. Not yet: salary advances, branch split | run total = journal total; no employee-level pay in any event |
| H7 Self-service | link employee ↔ membership at sign-in (verified email), own profile, payslips, leave requests | a member sees only their own payslips |
| Later | recruitment, appraisals, training, loan schedules, mobile clock-in, multi-currency payroll (after Finance FX) | — |

## Permissions (catalog 1.1.0)

| Permission | Sensitivity | Scopes |
| --- | --- | --- |
| `hr.employees.view` | 1 | organization, branch, department |
| `hr.employees.manage` | 2 | organization, branch, department |
| `hr.employment.manage` | 3 | organization, branch |
| `hr.documents.view` / `.manage` | 3 | organization, branch (view: department too) |
| `hr.personal.view` / `.manage` | 4 | organization |
| `hr.leave.view` | 1 | organization, branch, department |
| `hr.leave.manage` / `.approve` | 2 | organization, branch, department |
| `hr.leave.settings` | 3 | organization |

Roles: Line manager (view, decide on leave), HR officer (records, employment, documents, leave; no
personal details), HR manager (everything, not delegable). Payroll permissions arrive with H5
(higher sensitivity, SoD between preparing and approving a run).

## Pilot list (`planned` → `beta`)

H1–H3 done; a real organization's staff loaded; personal-details access reviewed with the
customer; backups restored once including the hr database.
