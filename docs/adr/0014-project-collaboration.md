# ADR-0014: Project collaboration: participating organizations, invitations, representatives

- Status: **Accepted** (2026-11-15). Phase 1 of the Tatook Projects collaboration brief; step 5 of
  ADR-0013 §11 (project participants), without the `partnerships` / `grant_reports` /
  `change_requests` data migrations, which stay for the partner-reports phase.
- App: `project-management` (Tatook Projects). Finance and Payroll are not touched.

## 1. What exists (inspection, 2026-11-15)

| Brief item | Exists | Where | Verdict |
|---|---|---|---|
| Organization identity | Yes | central `organizations`; PM mirrors it (`organizations`, `Organizations::mirror`) | Reuse |
| Organization classification | Partly | central `business_type` (free text) and `organization_type` (donor / organization, sign-up only) | Not needed by any acceptance criterion; see §7 |
| Organization members and invitations | Yes | central memberships and invitations; PM `organization_memberships` copy with permissions | Reuse, unchanged |
| Departments | Yes, twice | central `departments` (Structure); PM `org_units` (department, team, branch, country office) with members through `organization_memberships.org_unit_id` | Reuse PM `org_units`; add an optional manager |
| Project team | Yes | `project_members` (person, free-text role, allocation %); `people` need not be users | Extend: responsibilities and department |
| Donor ↔ organization relationship | Yes, organization-level | `partnerships` (email token, direct invitation, workspace a donor creates); `DonorProjection` gives the donor what each grant shares | Keep; it is not project-level |
| Project-level relationships between organizations | **No** | | **Build** |
| Project invitations between organizations | **No** (only the organization-level ones above) | | **Build**, reusing the partnership patterns (hashed tokens, landing on the inviter's host, accept in your own workspace) |
| Project-level authorization | **No**: access is organization-wide (catalog permissions) | `CurrentAccess`, Gate | Build for participants (§3); internal project scoping deferred (§6) |
| Approvals, requests, reporting | Yes, owner-side only | `change_requests`, `grant_reports`, expense approvals | Unchanged; participants submitting into them is the next phase |
| Audit | Yes | `audit_events` (append-only) | Reuse |
| Uncommitted `account_type` (funder / implementer) | Yes | PM, 2026-11-14 | Conflicts with "no permanent donor accounts". Left untouched: owner's decision (ADR-0013 §13.3) |

The cross-tenant pattern already in use: data stays in the organization that owns it; another
organization reads a reviewed projection (`DonorProjection`) run inside the owner's tenant
(`CurrentOrganization::run`). Participants follow the same pattern.

## 2. Decision

A project lives in **one owning organization** (the one that created it; ownership never moves on
invitation). Other organizations take part through explicit, project-scoped rows:

- **`project_participants`**: project, owning organization, participating organization,
  **relationship** (donor, lead implementing partner, implementing partner, sub-implementing partner,
  independent auditor, other authorized participant), **parent participant** (who it works under,
  so chains of any depth), explicit **capabilities**, status **active / suspended / completed /
  revoked**, who changed it and when. Rows are never deleted. An ended participation stays as
  history, and the organization can be invited again (a new row).
- **`project_invitations`**: project, inviting organization (the owner, or a participant inviting
  a sub-partner under itself), the organization invited (on Tatook) **or** an email address and
  contact name (not on Tatook yet), proposed relationship, parent and capabilities, message, hashed
  token (email only), expiry, and accepted / declined / revoked by whom and when. Nothing is granted
  before an authorized representative of the invited organization accepts.
- **`project_participant_members`**: which members of the participating organization represent it
  on the project, with their responsibilities. Being in the organization is not enough: an
  organization's other users do not see a shared project.
- `project_members` gains **responsibilities** and an optional **department** (`org_unit_id`);
  `org_units` gains an optional **manager**.

The relationship is a label. **Access comes only from the stored capabilities**, which the inviter
sets explicitly (the form pre-fills a suggestion per relationship).

## 3. Authorization

**Organization permission (catalog `projects` 3.1.0, additive):** `projects.partners.manage`:
invite organizations to the organization's projects and manage their participation; in a
participating organization, accept invitations, choose its representatives and invite
sub-partners. Given to `admin` and `manager`; owners hold everything.

**Project capabilities** (granted to a participant, `App\Collaboration\Capability`):

| Capability | Implies on the owner side | Effective now for participants |
|---|---|---|
| `view` View project | `projects.view` | Overview, milestones, participating organizations (always granted) |
| `edit` Edit project | `projects.manage` | Later |
| `activities.manage` | `projects.tasks.manage` | Later |
| `members.manage` Manage project members | `projects.manage` | Later (a participant always manages its own representatives) |
| `partners.manage` Manage partner relationships | `projects.partners.manage` | **Yes**: invite sub-partners under itself |
| `budget.view` View approved budgets | `projects.finance.view` | **Yes**: approved budget, spent, remaining |
| `budget.manage` | `projects.manage` + `projects.finance.view` | Later |
| `procurement.request` / `procurement.approve` | `procurement.request` / `procurement.approve` | Later |
| `reports.submit` / `reports.review` | `grants.manage` | Later (partner reports phase) |
| `requests.create` / `requests.review` | `projects.tasks.manage` / `projects.changes.approve` | Later (requests phase) |
| `results.view` View M&E results | `mel.results.view_published` | Later |
| `evidence.submit` | `mel.data.enter` | Later |
| `donor_reports.view` | `projects.donor.view` | Later |
| `reports.publish` | `mel.results.publish` | Later |

"Later" capabilities are stored and shown, so the next phases enforce them without migrating
anything; nothing reads them yet.

**No escalation.** Granting is capped by delegated authority, checked when the invitation is made,
when capabilities are changed, **and again when it is accepted**:
- An owner-side user needs `projects.partners.manage` and may grant a capability only if their own
  permissions imply it (column 2). An owner holds them all.
- A participant-side user needs `projects.partners.manage` in their organization, must represent
  it on the project (or hold that permission), and their participation must hold `partners.manage`.
  They may grant only capabilities their participation holds, and the new participant sits under
  theirs.
- At acceptance the inviter's authority is re-read from the stored membership access and the
  inviting participation's current state. If it has lapsed, acceptance is refused.

**Effective capabilities** = own ∩ parent's effective, and only while every participation up the
chain is active. Suspending or revoking a lead cuts off its sub-partners at once.

**Visibility for a participant.** Top-level participants see every participation. A nested
participant sees the owner, its own chain and those under it.

**Revocation** takes effect on the next request: every shared page and action re-reads
participation, chain and representative rows. Nothing is cached, and shared pages are sent
`Cache-Control: no-store, private`. There are no participant API tokens.

**Isolation.** Participant pages run in the participant's own workspace and read the owner's data
only through `App\Collaboration\ParticipantProjection` (named fields; never expenses, suppliers,
people's costs, grants, documents, internal notes or other organizations' data). Participation
grants nothing in the participant's or the owner's Finance, Payroll, HR or other records.

## 4. Flows

1. **Create a project**: unchanged. The creating organization owns it; no donor or lead implementer
   is asked for. An internal project simply has no participants.
2. **Invite** (owner, or a participant holding `partners.manage`): Project → Collaboration →
   Invite. Find an organization on Tatook (organizations with a published profile, by name, or any
   active one by exact workspace address, so names can't be trawled), or enter an email address
   and contact name. Choose relationship, parent (owner side), capabilities and message.
   Duplicates are refused: a pending invitation or an active / suspended participation for the same
   organization on the project.
3. **Accept / decline**:
   - An organization on Tatook: its `projects.partners.manage` holders are notified and answer in
     Shared projects.
   - An email invitation: the emailed link opens on the inviter's host (as partnership invitations
     do). The person says which workspace their organization uses (or creates one on the platform
     first, which is the controlled registration) and accepts there.

   Acceptance runs in one transaction with a row lock: re-check authority, refuse an organization
   that already takes part or owns the project, create the participation, and record audit on both
   sides.
4. **Revoke / resend** invitations. Resend gives an expired or pending email invitation a new token
   and expiry (the old link stops working), or a direct one more time.
5. **Manage participation** (owner side, `projects.partners.manage`): change capabilities (capped),
   suspend, reactivate, complete, revoke. Completed and revoked are final.
6. **Representatives** (participant side, `projects.partners.manage`): add or remove the
   organization's members who work on the project, with responsibilities.

## 5. Database (additive only)

`project_participants`, `project_invitations`, `project_participant_members` are new.
`project_members.responsibilities` (json) and `project_members.org_unit_id` and
`org_units.manager_person_id` are new nullable columns.

- Foreign keys: the project in its owner's tenant (composite key on PostgreSQL), organizations,
  users.
- Partial unique indexes on PostgreSQL: one live (active / suspended) participation per project
  and organization, and one pending invitation per project and invited organization. The actions
  check the same under a lock everywhere.
- The two cross-organization tables are not organization-scoped models (as `partnerships`): every
  query starts from the owner's project or the participant's organization.

## 6. Deferred, on purpose

- **Project-scoped access for internal users** (users without organization-wide `projects.view`
  seeing only projects they are on). It changes who sees what in a live app and touches every
  project route; next step, behind an explicit opt-in role.
- Participants writing into the owner's records: partner reports, requests, procurement requests,
  evidence, tasks. These are the "later" capabilities above.
- Migrating `partnerships` into participations, and the donor portal reading participations.
- Organization classification (§7).
- Reading central `departments` instead of PM `org_units` (ADR-0013 §6).

## 7. Organization classification

The brief's list (NGO, government, business, foundation, international organization, educational
institution, other) belongs on the central organization, as identity. It grants nothing, and no
acceptance criterion depends on it. It is a separate small change across central (column, settings
field, integration API), platform-client and the PM mirror, and it is not part of this phase.

## 8. As built (2026-11-15, uncommitted)

- Domain `App\Collaboration`: `Capability`, `Relationship`, `Responsibility`, `ParticipantAccess`
  (effective capabilities through the chain, visibility, who may open), `Collaboration` (every
  write, with the authority checks), `ParticipantProjection`, `Rows`; models `Participant`,
  `Invitation`, `Representative`. Migration `2026_11_15_200000_create_project_collaboration`.
- Owner side: Project → **Partners** tab (`projects/{project}/collaboration`): participating
  organizations, changing access and status, invite form, invitations with withdraw / send again.
  Organization search: `collaboration/organizations`.
- Participant side: **Shared projects** in the menu (`shared-projects`): invitations waiting,
  projects taken part in, each project's shared view, who works on it for us, sub-partners and
  invitations sent. Emailed links: `project-invitations/{token}` (landing on the inviter's host),
  then `project-invitations/{token}/open` in the invited workspace.
- Team: responsibilities and department on project members (`PUT projects/{project}/members/{member}`);
  departments (Administration → Departments and teams) take an optional manager.
- Catalog `projects` **3.1.0**: `projects.partners.manage`, given to `admin` and `manager`.
- Changing capabilities refuses only what is **added** beyond the changer's authority; taking away
  is always allowed, and capabilities already held that the changer could not give are kept.
- Tests: `tests/Feature/CollaborationTest.php` (19 cases: internal project, both invitation
  directions, acceptance required, projection only, tenant isolation, representatives, permission
  and capability ceilings, duplicates, re-check at acceptance, sub-partners and visibility,
  suspension and revocation cutting off the chain, history kept and re-invitation, email
  invitations with token-only acceptance, resend and revoke, decline, foreign organizations,
  organization search, team responsibilities and departments, audit on both sides).

**Deploy:** back up the Projects database, migrate, then on central
`php artisan platform:catalog-reassign projects --dry-run`, then without `--dry-run`.
