# ADR-0018: One project-participation model for donors and partners

Status: **Proposed — for independent review. Nothing in this ADR is
implemented; no table is merged, renamed or deleted.**

Revision 2 (2026-11-16): relationship dimensions separated, consolidation
steps 1–8, affected code, invitation and history preservation.

## 1. What exists (inspected 2026-11-16)

| Channel | Responsibility | Data model | Access model | Existing records |
|---|---|---|---|---|
| **Donor portal** (`routes/web/donor.php`, `DonorPortalController`, `GrantPortalController`, `App\Actions\DonorPortal`) | A funder's people see the grants the organization chose to share | `funders` (per-organization directory), `funder_contacts` (a user acting for a funder, member of the *implementer's* workspace with only the funder-contact role), `grants.portal_*` switches | Member of the implementer's tenant with a restricted role; `ConfineFunderContacts` middleware; `DonorProjection` per grant | Live in production since 2026-11-11 (counts not inspected: no production access in this milestone) |
| **Partnership** (`App\Models\Partnership`, `App\Actions\Beneficiaries`, `BeneficiaryController`, `DonorConnectionController`, `DonorDashboardController`, `DonorWorkController`, `FundingInterestController`, `FundingListingController`, `MarketplaceController`, `Organizations/PartnershipController`) | Donor organization ↔ beneficiary organization, across workspaces; grants shared; donor reviews grant reports; donor-created claimable workspaces | `partnerships` (not tenant-scoped; donor, beneficiary or invited organization, `funder_id` unique, token, accepted/declined, `platform_claim_id`, `created_organization_id`), `funding_interests.partnership_id` | Donor's own workspace, `RequireFunding` (`Organization::isDonor()`), `DonorProjection` | Live in production |
| **Project participants** (ADR-0014/0015) | Any organization taking part in one project, with relationship, chain (lead → sub-partner), capabilities, reports and requests | `project_participants`, `project_invitations`, `project_participant_members`, `report_obligations`, `project_reports`, `project_report_versions`, `project_requests` | Participant's own workspace; `ParticipantProjection`; capabilities (`Capability::operational`) | Not deployed (local only) |
| **M&E partners** (`App\Mel\Models\Partner`) | Label for the organization running a programme unit | `mel_partners` (name, kind; tenant-scoped) | None: a label, no access | Not deployed |

Overlaps: a donor can today be a funder contact (portal), a partnership
donor, and a project participant with relationship "donor", each with its
own projection and its own definition of budget and spending.

## 2. Decision proposed

**`project_participants` is the one authoritative model of "another
organization's role in a project".** It is the only model that is
cross-workspace, per project, chain-aware and capability-based. No new
relationship engine is built. The other channels keep their *specific*
responsibility and are expressed on top of it:

- **Partnership** stays the *organization-level* relationship (donor ↔
  implementer across projects: who may invite whom, the funding marketplace,
  donor-created workspaces). It stops being a route to project data by
  itself; project data reaches the donor only through participations.
- **Donor portal** stays for funders **not on Tatook** (people inside the
  implementer's workspace). It is not removed while funders use it.
- **M&E partners** become a reference to a participation (nullable
  `participant_id`), keeping the name for organizations not on Tatook.
- **Account type** (`organizations.account_type`) chooses the menu only. An
  organization can be a donor on one project and an implementer on another.

### 2.1 Seven separate dimensions (none implied by another)

| Dimension | Question | Where it lives | Notes |
|---|---|---|---|
| Ownership | Whose project is it? | `projects.organization_id` (tenant) | Exactly one owner. The owner's own access is ADR-0016. |
| Funding | Who funds it, with what? | `grants` + `grant_allocations` (owner's books); proposed `participant_grants` join (participation ↔ grant) | A donor participation without a grant is allowed (oversight-only). Several grants per donor allowed. |
| Implementation | Who delivers which part? | participation `relationship` (`implementer`, `sub_partner`, …) + `parent_participant_id` | Chains of any depth; no fixed tiers. |
| Participation | Is the organization in, and since/until when? | `project_participants.status`, `joined_at`, `ended_at` | Ending keeps history. |
| Reporting | Who reports to whom? | `report_obligations` (from → to participation or owner) | Independent of the chain: a sub-partner may report straight to a donor if an obligation says so. |
| Approval | Who decides on what? | owner permissions (`projects.requests.review`, `projects.changes.approve`, …); a participation's `requests.review` capability for its children | Finance approvals stay in Finance (ADR-0017). |
| Visibility | What may it see? | participation capabilities (`view`, `budget.view`, `reports.view`, …) server-side, plus ADR-0016 inside the owner | A capability is offered only if enforced (`Capability::operational`). |

### 2.2 Supported shapes (tests in `MultiPartyAccessTest`, `ProjectCollaborationTest`, `PartnerReportsTest`)

| Shape | Expressed as |
|---|---|
| Internal project | No participations; ADR-0016 visibility |
| Donor + implementer, donor owns the project | Owner = donor, participation = implementer |
| Donor + implementer, implementer owns it | Owner = implementer, participation = donor (+ grant link) |
| Donor → lead → sub-partner | Lead participation; sub-partner with `parent_participant_id` = lead |
| Several donors | One donor participation each, each with its grants |
| Several partners side by side | One participation each; siblings never see each other's reports/requests (tested) |

## 3. Consolidation steps

**Step 1 — Identify records.** Per organization, read-only counts of:
accepted partnerships and their shared grants; funder contacts; open
partnership invitations (token not expired, not accepted/declined); grant
reports; `mel_partners`; project participations and invitations. Output a
CSV per environment. (Command to write: `projects:participation-inventory`,
read-only.)

**Step 2 — Map to the target.**

| From | To | Mapping |
|---|---|---|
| Accepted partnership + grant shared with it + project that grant funds (`grant_allocations`) | donor participation on that project, `origin = partnership`, `origin_id = partnerships.id` | capabilities from the grant's portal switches: `portal_budget` → `budget.view`, `portal_reports` → `reports.view`, always `view` |
| Partnership without a funded project | nothing (stays org-level) | — |
| Funder contact | unchanged | optional move when the funder joins Tatook |
| `mel_partners` row | `participant_id` where a person confirms the match | never automatic |
| Grant reports | kept as they are | new obligations use partner reports |

**Step 3 — Conflicts.** Listed, never resolved automatically:
an organization already a participant on the project with another
relationship (keep the existing one, add the grant link only); two
partnerships for one donor–project pair through different grants (one
participation, several grant links); a portal switch with no equivalent
capability (dropped and listed); a funder contact *and* a partnership for
the same funder (both kept; the contact is offered the move).

**Step 4 — Invitations.** Open partnership invitations stay valid and keep
working on their current pages; accepting one after the migration also
creates the participations of step 2 (same mapping). Project invitations are
untouched. No token is reissued.

**Step 5 — Historical references.** Nothing is re-pointed. Grant reports
keep `partnership`/`grant` references; partner reports and requests keep
their `participant_id` and frozen versions (`AppendOnly`). Participations
created by migration carry `origin` so their history is traceable.

**Step 6 — Affected code.** Read paths only in stages 1–3:
`DonorProjection` and `ParticipantProjection` (one definition source:
`ProjectFinance`), `DonorDashboardController`, `DonorWorkController`,
`BeneficiaryController` (show the project participations created),
`App\Actions\Beneficiaries::accept` (step 4), `App\Mel` unit pages
(`participant_id`). Routes unchanged.

**Step 7 — Reversible migrations.** Additive only: `participant_grants`
table; `project_participants.origin`, `origin_id` (nullable);
`mel_partners.participant_id` (nullable, null on delete). Rollback: delete
participations with `origin = partnership` that have no reports, requests or
members added after creation (others are listed for a person), drop the new
columns. Partnerships, funders and contacts are never written.

**Step 8 — Compatibility.** Every existing page keeps working throughout.
The portal and partnership pages stay. A donor must see the same approved
budget and paid amount through the portal, the partnership dashboard and a
participation (stage 1 test). Duplicate entry points are retired only when
measured unused, each with its own decision.

## 4. Stages

1. **Align definitions**: `DonorProjection` uses `ProjectFinance`; a test
   asserts identical figures through all three channels.
2. **Inventory command + additive columns** (steps 1, 7); no behaviour change.
3. **Pilot**: create participations from partnerships for one organization
   on staging; compare before/after views.
4. **Retire duplicate entry points** only when unused.

## 5. Security notes (verified this milestone)

- Sibling partners cannot read each other's reports or requests, including
  by placing their own participation id in front of another's record (404).
- The owner's staff need `projects.requests.raise`/`review` (or manage /
  change approval / grants) to read requests; `projects.view` alone lists
  none (fixed in this milestone; requests carry amounts).
- A donor sees budget figures only with `budget.view`; personnel cost never.
- Open item: top-level participants see the names and relationships of all
  live participations in the project. Decision needed (§6.4).

## 6. Questions for the reviewer

1. Is `project_participants` the right authority, given that Partnership and
   the donor portal are the ones live in production?
2. Must a donor participation reference a grant?
3. Keep the donor portal for off-platform funders indefinitely?
4. Should participants see the list of other participants (names and
   relationships), only their own chain, or nothing?
