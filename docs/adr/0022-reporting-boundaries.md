# ADR-0022: Reporting across organizations, and its boundaries

- Status: accepted (2026-11-17, Phase 5 milestones 10–11)
- Context: Project Management, M&E (`mel-app`), Finance
- Related: ADR-0015 (partner reports and requests), ADR-0017 (Finance owns
  project payments), ADR-0019 (standalone M&E), ADR-0021 (grant agreements)

## Decision: who owns which report

| Report | Owner | Where | The other party sees |
| --- | --- | --- | --- |
| Progress/narrative reports between project parties, with review (submit, approve, ask for changes, reject) | Project Management | `report_obligations`, `partner_reports` (ADR-0015) | The organization it is routed to, through its participation |
| Report schedules (templates of period, kind and route) | Project Management | Report obligations generate one report per period (ADR-0015 §4) | Same |
| Reports owed to a funder under a grant | Project Management (recipient) | `grant_reports`, decided by the donor on Tatook or through the donor portal | The donor, for its own grants |
| Grant money by stage, and use of funds per project | Project Management (recipient), computed | `App\Reports\GrantFinancialReport` (page panel and CSV) | The donor: funding in full; procurement and commitments only when the grant shares commitments, payments made only when it shares spending |
| Disbursements: requested, approved, sent, received, disputed | Project Management | `grant_disbursements.status` (ADR-0021) | The donor, for its own grants |
| Budget against actual for a project | Project Management | Budget versions and lines, spending (budget CSV) | The donor, per grant share flags (`DonorProjection`) |
| Tasks and milestones | Project Management | Board, timeline, and shared tasks across organizations (Phase 5 M8) | A participation: tasks shared with it or given to it. A donor: counts, when the grant shares tasks |
| Indicators, targets, results, data collection | M&E (`mel-app`) | `App\Mel`, `App\Collect` (ADR-0019) | Through M&E's own sharing. A partner report captures an M&E snapshot when submitted (`ReportContent::mel`) |
| Ledger, payments made, receipts in the books | Finance | Finance app (ADR-0017) | Project Management reads it back; it keeps no second ledger |

## Rules

1. **No second ledger.** Every amount in a report comes from records that
   already exist (allocations, disbursements, purchase requests, expenses
   that Finance settles). A report computes; it never stores its own money.
2. **Stages are never summed into each other** (awarded, agreed, requested,
   approved, sent, received; allocated, procurement, committed, paid). Nor
   are currencies: each row carries its own.
3. **"Sent" is the donor's record**, not Finance's, and is labelled so
   (ADR-0021). "Paid" is what Finance settled: expenditure recognized on the
   cash basis the books use.
4. **Cross-organization reads go through one gate per relation:**
   `DonorProjection` (donor ↔ recipient, by `funder_id` and share flags),
   `ParticipantAccess`/`Acting` (project participants), and
   `PartnerWaiting` (an organization's own to-do list). No controller
   queries another organization's rows by id without one of these.
5. **M&E results stay in M&E.** Project Management does not compute or store
   indicator values. It links to M&E, and a partner report keeps the
   snapshot it was submitted with.
6. **Exports** go through `CsvExport`. Text a spreadsheet would run as a
   formula is written as text.

## Not built (Phase 5)

- A partner-hierarchy report: who brought in whom, and each one's reporting
  standing, as one page. The data exists (`project_participants.parent_participant_id`,
  partner reports).
- Report templates with custom fields. Obligations set the period, kind and
  route; the content stays free text, files and the M&E snapshot.
- PDF exports. CSV only.
