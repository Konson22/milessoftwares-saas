# ADR-0018: One project-participation model for donors and partners

Status: **Proposed — for independent review. Nothing in this ADR is
implemented; no table is merged, renamed or deleted.**

## 1. What exists (inspected 2026-11-16)

| Channel | Responsibility | Data model | Access model | Existing records |
|---|---|---|---|---|
| **Donor portal** (`routes/web/donor.php`, `DonorPortalController`, `App\Actions\DonorPortal`) | Lets a funder's people see the grants the organization chose to share | `funders` (per-organization directory), `funder_contacts` (a user acting for a funder, member of the *implementer's* workspace with only the funder-contact role), `grants.portal_*` switches | Member of the implementer's tenant with a restricted role; `ConfineFunderContacts` middleware; `DonorProjection` per grant | Live in production since 2026-11-11 (counts not inspected: no production access in this milestone) |
| **Partnership** (`App\Models\Partnership`, `App\Actions\Beneficiaries`, `routes/web/beneficiaries.php`) | Donor organization ↔ beneficiary/implementer organization, across workspaces; grants shared; donor reviews grant reports | `partnerships` (not tenant-scoped), invitation by email / direct / donor-created workspace (`platform_claim_id`) | Donor's own workspace, `RequireFunding` (`Organization::isDonor()`), `DonorProjection` | Live in production |
| **Project participants** (ADR-0014/0015) | Any organization taking part in one project, with relationship, chain (lead → sub-partner), capabilities, reports and requests | `project_participants`, `project_invitations`, `project_participant_members`, `report_obligations`, `project_reports`, `project_requests` | Participant's own workspace; `ParticipantProjection`; capabilities | Not deployed (local only) |
| **M&E partners** (`App\Mel\Models\Partner`) | Label for the organization running a programme unit | `mel_partners` (name, kind; tenant-scoped) | None: a label, no access | Not deployed |

Overlaps: a donor can today be a funder contact (portal), a partnership
donor, and a project participant with relationship "donor", each with its
own projection and its own definition of budget and spending.

## 2. Proposal

**`project_participants` becomes the one authoritative model of "another
organization involved in a project"**, because it is the only one that is
cross-workspace, per project, chain-aware and capability-based. The others
keep their *specific* responsibility and are re-expressed on top of it:

- **Funding relationship** = a participation with relationship `donor` plus
  the **grant** that funds the project (existing `grants` /
  `grant_allocations`, linked by a new nullable `grant_id` on the
  participation — or a join table for several grants). Multiple donors =
  multiple donor participations, each with its grants.
- **Partnership** stays as the *organization-level* relationship (donor ↔
  implementer across projects: who may invite whom, the shared grant list).
  It stops granting project access by itself; project access comes from
  participations created under it.
- **Donor portal** (funder contacts inside the implementer's workspace)
  remains for funders **not on Tatook**. When a funder joins Tatook, its
  contacts are offered a move to a donor participation; the portal is not
  removed while funders use it.
- **M&E partners** become a reference to a participation (new nullable
  `participant_id` on `mel_partners`), keeping the name for organizations
  not on Tatook. Units then derive access from the participation (stage 3).
- **Account types** are not hard-coded: `organizations.account_type`
  (funder/implementer) only chooses the menu; what an organization may do on
  a project comes from its participation's relationship and capabilities.
  An organization can be a donor on one project and an implementer on
  another.

### Supported shapes

| Shape | Expressed as |
|---|---|
| Internal project | No participations; ADR-0016 visibility |
| Donor + implementer (donor owns the project) | Owner = donor, participant = implementer |
| Donor + implementer (implementer owns it) | Owner = implementer, participant = donor (+ grant link) |
| Donor + lead + sub-partner | Lead participation; sub-partner participation with `parent_participant_id` = lead |
| Multiple donors | One donor participation per donor, each with its grants |
| Multiple partners | One participation each; chains as needed |

## 3. Migration map (proposed, not executed)

| From | To | How | Rollback |
|---|---|---|---|
| `partnerships` (accepted) + shared grants | donor participations on the projects those grants fund | Script creates participations with relationship `donor`, capabilities mapped from the portal switches (`portal_budget` → `budget.view`, …); partnership kept | Delete the created participations (tagged `origin = partnership`); partnerships untouched |
| `funder_contacts` | unchanged; optional move when the funder joins Tatook | Per funder, on request | n/a |
| `mel_partners` | `mel_partners.participant_id` set where the name matches a participant organization | Suggested matches confirmed by a person, never automatic | Null the column |
| Grant reports (`grant_reports`) | kept; partner reports (ADR-0015) used for new obligations | No data move | n/a |

## 4. Compatibility

- Every existing page keeps working during the transition; the new links
  are additive columns.
- Projections: `DonorProjection` and `ParticipantProjection` must share the
  definitions in docs/finance/metric-definitions.md before any merge (stage
  2), so a donor sees the same numbers whichever way it arrived.

## 5. Stages

1. **Align definitions** (projections use `ProjectFinance`); tests that a
   donor sees the same approved budget and paid amount through the portal
   and through a participation.
2. **Additive links**: `grant_id` on participations, `participant_id` on
   `mel_partners`; UI to link; no behaviour change.
3. **Create participations from partnerships** for one pilot organization on
   staging; compare what the donor sees before and after.
4. **Retire duplicate entry points** only when no organization uses them
   (measured), each with its own decision.

## 6. Questions for the reviewer

1. Is `project_participants` the right authority, given that Partnership and
   the donor portal are the ones live in production?
2. Should a donor participation be required to reference a grant?
3. Should the donor portal for off-platform funders be kept indefinitely?
