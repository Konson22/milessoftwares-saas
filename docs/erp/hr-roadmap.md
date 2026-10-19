# HR & Payroll roadmap

`hr-app`, served at `{workspace}/hr`, one registry application `hr`
([ADR-0005](../adr/0005-hr-and-payroll-application.md)). Every phase: tests, Pint, PHPStan,
`vp check`, `tsc` and build green; migrations rolled back and forward on PostgreSQL; results in
`implementation-status.md`. The application stays `planned` (shown as coming soon, not sold) until
the pilot list below is met.

| Phase | Scope | Acceptance |
| --- | --- | --- |
| H0 Foundation ✅ | platform API for departments and positions; `hr-app` from the template; catalog (roles, sensitivity); ops entries | structure lists never show another organization's rows; platform-client caches and fakes them |
| H1 Staff records ✅ | profiles (work details), employee numbers, managers, employment history (hired, transfer, promotion, change, left), contracts, leaving, encrypted personal details, HR audit log | branch- and department-scoped members see only their staff; personal details unreadable in the database and in the log; nothing changes for someone who has left |
| H2 Documents ✅ | `hr_documents` private disk, upload (type and size allowlist), signed downloads, expiry dates and reminders (contracts, permits, licences), backups include the files | no document reachable without a signed, short-lived URL; expiring documents listed on the home page |
| H3 Leave | leave types and entitlements per year, balances, requests, approval by the line manager (SoD: not one's own), public holidays | balance never negative unless the type allows it; approving one's own request refused |
| H4 Attendance | timesheets, `payroll.timesheet.submitted` and `hr.employee.joined` from other products (audience `hr`) | duplicate event is a no-op; hours land against the right employee |
| H5 Payroll | pay components (earnings, deductions), effective-dated pay per employee, statutory rule tables (South Sudan preset), runs: prepare → approve → lock, payslips (PDF), adjustment runs; gated by the `payroll` plan feature | preparer cannot approve (catalog SoD `block`); a locked run never changes; payslip totals equal the run |
| H6 Finance | `finance.payroll.posted` (one summarised journal per run) and `finance.payroll.paid`, salary advances; Finance system accounts | run total = journal total; no employee-level pay in any event; split lines only above minimum headcount |
| H7 Self-service | link employee ↔ membership at sign-in (verified email), own profile, payslips, leave requests | a member sees only their own payslips |
| Later | recruitment, appraisals, training, loan schedules, mobile clock-in, multi-currency payroll (after Finance FX) | — |

## Permissions (catalog 1.0.0)

| Permission | Sensitivity | Scopes |
| --- | --- | --- |
| `hr.employees.view` | 1 | organization, branch, department |
| `hr.employees.manage` | 2 | organization, branch, department |
| `hr.employment.manage` | 3 | organization, branch |
| `hr.documents.view` / `.manage` | 3 | organization, branch (view: department too) |
| `hr.personal.view` / `.manage` | 4 | organization |

Roles: Line manager (view), HR officer (records, employment, documents; no personal details),
HR manager (everything, not delegable). Payroll permissions arrive with H5 (higher sensitivity,
SoD between preparing and approving a run).

## Pilot list (`planned` → `beta`)

H1–H3 done; a real organization's staff loaded; personal-details access reviewed with the
customer; backups restored once including the hr database.
