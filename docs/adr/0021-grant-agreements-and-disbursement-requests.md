# ADR-0021: Grant agreements and disbursement requests between organizations

- Status: accepted (2026-11-17, Phase 5 milestones 6–7)
- Context: Project Management (`project-management`), Funding module
- Related: ADR-0014 (project collaboration), ADR-0017 (Finance owns project payments)

## Context

A grant (`grants`) is the recipient organization's record of money it was
promised: funder, amount, dates, allocations to projects, a schedule of
payments (`grant_disbursements`) and reports. Until now every step was
one-sided: the recipient recorded the award, scheduled the payments and
marked each received. A donor connected on Tatook (a `partnerships` row,
`funder_id` linking the recipient's funder record to it) could read a grant
shared on its portal, decide reports and post messages, but approve nothing.

Phase 5 asks for bilateral approval of the agreement and a disbursement
lifecycle (planned → requested → approved → sent for payment → paid → receipt
confirmed → failed/disputed), with Finance executing the payment where the
integration contract supports it.

## Decision

1. **One record, two parties.** The grant stays the recipient's, in its
   organization; no copy is made on the donor's side and no second ledger is
   started. `App\Actions\GrantAgreements` is the only place either party moves
   an agreement or a payment. Donor actions run inside the recipient's
   organization (`CurrentOrganization::run`) after `connected()` proves the
   partnership is the acting donor's and accepted, and every grant/payment is
   re-scoped through `partnership.funder_id` and the beneficiary organization.
2. **Agreement.** The recipient proposes the grant's terms (amount, end date,
   a note); the donor approves or declines (a reason is required to decline).
   Approval stores `agreement_amount_minor`, which becomes a ceiling: award
   and amendment above it are refused until the donor approves a new
   proposal, and payment requests cannot add up to more. Proposing a grant
   shows it to that donor even if it is not shared on the portal.
3. **Disbursements** carry a `status`:
   `planned → requested (recipient) → approved | rejected (donor)`,
   `approved → sent (donor) → received | disputed (recipient)`,
   `disputed → sent (donor, corrected)`, `rejected → requested`.
   A payment with the donor (requested and beyond) cannot be removed.
4. **Sent is the donor's own record**: the date and its transfer reference.
   It is never a payment confirmation: no step marks money received for the
   recipient. The recipient confirms receipt itself, which runs the existing
   `Grants::receive` (and so the project books posting); or it disputes, with
   a reason, and the donor records the transfer again.
5. **Audit on both sides** for every step, and each step notifies the other
   party's grant holders.
6. **Grants with no connected donor are unchanged**: no agreement block, the
   recipient schedules and receives as before.

## The Finance blocker (M7)

Finance's integration contract (ADR-0017) covers a project's **own**
payments: Project Management requests a payment, Finance approves and pays it
from the organization's books, and reports back. There is no contract for a
**transfer between two organizations** (donor's Finance paying the
recipient), nor a way for Finance to confirm a receipt in another
organization's account. Building one means a new Finance API (outgoing grant
transfer from the donor's books, with its own approval and reconciliation)
and is out of scope here.

So: the "sent for payment" and "paid" steps are recorded by the donor by
hand, labelled as its own record, and nothing is fabricated as confirmed by
Finance. When Finance gains a grant-transfer contract, the donor's
`markSent` becomes the point where Project Management requests the transfer
from the donor's Finance, and Finance's callback moves the payment to sent;
the recipient still confirms receipt.

## Consequences

- Migration `2026_11_19_000400` is additive; existing payments read as
  `planned`, or `received` when they were. Its `down()` refuses once any
  agreement or request exists. PostgreSQL CHECKs keep `status = received` in
  step with `received_on`.
- `DonorProjection::canSee`/`shared` widen to grants with an agreement in
  play, for that funder only.
- Not done: a donor-initiated grant (the donor drafting the terms), partial
  receipts, and multi-currency transfer amounts. Each would extend
  `GrantAgreements`, not add a second engine.
