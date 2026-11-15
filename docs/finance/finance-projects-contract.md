# Finance ↔ Projects contract (Phase 3B)

Status: **infrastructure built in Projects, switched off.** No organization is
integrated. Finance does not yet handle `finance.spending.*`, and the platform
cannot yet issue Finance-to-Projects event tokens (see §9, Blockers). This
document is the contract both sides build to. ADR-0017 §6 is the decision it
implements.

## 1. Ownership (unchanged by this phase)

| Concern | Owner today (legacy) | Owner once integrated |
|---|---|---|
| Budgets, operational approval of spending | Projects | Projects |
| Commitment (approved, unpaid spending) | Projects | Projects (also counted from the request) |
| Acceptance of a payment request | — | Finance |
| Financial approval (how much will be paid) | — | Finance |
| Payment execution | Projects records it; Finance posts the outbox event | **Finance** (only its outcomes mark money paid) |
| Accounting posting (journals) | Finance | Finance |
| Payroll compensation | Payroll (part of Finance) | Payroll (unchanged) |
| Staff advances, petty cash | Projects / Finance funds | Unchanged in this phase (§10) |

Projects never posts journals. Finance never decides operational approval.
Neither side rewrites the other's history.

## 2. Transport

Both directions use the same machinery as the existing product → Finance events.

* **Envelope**: `contracts/events/envelope.schema.json`. `event_type`
  starts with the receiving module, so `finance.*` goes to Finance and
  `projects.*` goes to Projects. The shared kit's `Envelope` now accepts
  `projects`.
* **Sender**: the platform outbox (`miles/platform-client`). The event is
  written in the sender's own transaction and delivered after commit.
  * 201 and 200 are final.
  * 401, 403, 409 and 422 fail the event, which is not retried.
  * Anything else (including 503) is retried with backoff, up to 8 attempts.
* **Authentication**: each delivery carries a platform-signed RS256 event
  token (5-minute TTL). The token's claims are:
  * `aud`: the receiving module;
  * `sub`: the sending application;
  * `org`: the organization;
  * `scope`: `events.write`.

  The receiver verifies the token against the platform's JWKS.
* **Idempotency**: the receiver's `shared_module_inbox` is keyed on
  (source_app, organization_id, source_transaction_id):
  * the same content again → 200 `duplicate`, not applied twice;
  * the same id with different content → 409 `conflict`.
* **Endpoints**:
  * Finance: `POST {workspace}/finance/events`, which exists.
  * Projects: `POST {workspace}/projects/events`, which is new and only
    receives `projects.*`.

## 3. Projects → Finance

Sent only when the organization is in `integrated` mode **and**
`PROJECTS_FINANCE_INTEGRATION=true`.

| Event | When | source_transaction_id |
|---|---|---|
| `finance.spending.submitted` v1 | spending gets its last operational approval (`Spending::approve`) | `finance-request:{request_id}:r{revision}:submitted` |
| `finance.spending.cancelled` v1 | Projects cancels approved spending of which nothing is paid | `finance-request:{request_id}:r{revision}:cancelled` |

### Stable references on every request message

| Field | Meaning |
|---|---|
| `originating_app` | always `projects` |
| `organization_id` | the organization (also in the envelope and the token) |
| `project_id` | the project |
| `request_id` | Projects' id for the request; Finance quotes it in every outcome |
| `correlation_id` | constant for the request, both directions |
| `currency`, `amount_minor` | the project currency, as an integer in minor units |
| `version` | the request revision (1 for now; a resubmission would be 2) |
| `contract_version` | 1 |
| `idempotency_key` | `finance-request:{request_id}:r{revision}`; Finance creates at most one payment request per key |
| `created_at` | when the request was submitted |
| `status` | Projects' authoritative status at send time |

The schemas are in `contracts/events/finance.spending.*.v1.schema.json`.

### Specified, not emitted in this phase

These need decisions first (§11):

* `finance.commitment.created` and `finance.commitment.released` for
  procurement. Purchase orders already become approved expenses, so for now
  they travel as `finance.spending.submitted`. A separate commitment event
  belongs to Procurement work, which is out of scope.
* `finance.advance.requested`: staff advances stay in Projects in this phase.
* Funding-related requests (grant drawdowns): grant receipts already use
  `finance.grant.received`.

## 4. Finance → Projects (outcomes)

Every outcome carries `request_id`, `correlation_id` and `sequence`.
`sequence` is Finance's count of outcomes for that request, starting at 1 with
no gaps. The schemas are in `contracts/events/projects.*.v1.schema.json`.

| Event | Meaning | Status after |
|---|---|---|
| `projects.finance_request.accepted` | Finance took it in; gives `finance_reference` | accepted |
| `projects.finance_request.rejected` | Finance refused it (`reason`) | rejected (final) |
| `projects.finance_request.approved` | Finance will pay `approved_minor` (≤ amount) | approved |
| `projects.finance_request.cancelled` | Finance confirms the cancellation Projects asked for | cancelled (final) |
| `projects.finance_payment.completed` | money paid (`payment_id`, amount, currency, `paid_on`, `journal_reference`) | partially_paid or paid |
| `projects.finance_payment.failed` | a payment attempt failed | failed |
| `projects.finance_payment.reversed` | money paid was taken back (`payment_id`, amount) | partially_paid or reversed |
| `projects.finance_transaction.corrected` | posting corrected (`journal_reference`); never an amount | unchanged |

## 5. State machine

The five decisions are kept apart:

* **operational approval** happens in Projects, before the request exists;
* **Finance acceptance** is accepted or rejected;
* **financial approval** is approved;
* **payment execution** is partially paid, paid, failed or reversed;
* **accounting posting** shows up only as references (journal_reference, or a
  correction), never as a status.

```
submitted ──accepted──► accepted ──approved──► approved ──payment──► partially_paid ──payment──► paid
    │                      │                      │  ▲                   │  ▲                  │
    │                      │                      │  └──────failed◄──────┘  └──reversal─────────┤
    ├──rejected────────────┴──rejected            └──failed──► failed ──payment──► (partially_)paid
    └──(Projects) cancellation_requested ◄── accepted / approved / failed                       ▼
                     │                                                         reversed (all reversed)
                     ├──cancelled (Finance confirms)
                     ├──rejected
                     └──payment (Finance paid before it saw the cancellation: recorded, flagged)
```

The allowed moves are in code: `RequestStatus::next()`. Anything else is
answered 409 `invalid_transition` and recorded. Every move appends a row to
`finance_request_transitions`, which is append-only. On PostgreSQL a trigger
enforces this; everywhere else the model does. Each row records from, to,
cause, source, event id, sequence, facts and time.

Amounts:

* `approved_minor` ≤ `amount_minor`;
* cumulative payments ≤ approved (or the amount if not approved);
* a reversal ≤ what is left of that payment;
* a currency other than the request's → 422 `currency_mismatch`;
* a correction carrying an amount → 422.

PostgreSQL also has CHECK constraints: `paid_minor ≤ amount_minor` and
`reversed_minor ≤ paid_minor`.

**The expense is not rewritten.** In integrated mode the expense stays
`approved` in Projects; it is the operational record. Projects' figures
(`ProjectFinance`) read the request: what Finance reports paid, net of
reversals, moves from committed to paid. The provenance then says
`source: projects_and_finance`.

## 6. Ordering, idempotency, retries

| Situation | Answer | Kept? |
|---|---|---|
| first delivery | 201 | inbox row, transition |
| exact retry (same transaction id, same content) | 200 duplicate | no change |
| same transaction id, other content | 409 conflict | rejection row |
| same Finance `payment_id` under another transaction id | 409 duplicate_payment | rejection row |
| `finance_reference` already used by another request | 409 duplicate_reference | rejection row |
| outcome arrives early (sequence gap) | 503 + Retry-After | nothing; Finance's outbox retries |
| outcome older than the last applied | 409 stale_sequence | rejection row |
| unknown request, payment, or wrong correlation id | 422 | rejection row |
| malformed envelope, unsupported type or version | 422 | rejection row |
| organization not integrated or suspended | 409 not_integrated | rejection row |

A refused event changes nothing except adding its rejection row: the inbox
transaction is rolled back first. Nothing is resent automatically.

## 7. Organization modes

| Mode | Spending approved | Paid by | Outcomes taken |
|---|---|---|---|
| legacy (everyone) | stays in Projects | Projects (outbox tells Finance) | no |
| reconciliation_required | stays in Projects | Projects | no |
| ready | stays in Projects | Projects | no |
| integrated | submitted to Finance | Finance only | yes |
| suspended | stays in Projects (not sent) | Finance for open requests; Projects' pay paths still refused | yes |

The allowed switches (`FinanceMode::next()`):

* legacy → reconciliation_required;
* reconciliation_required → ready or legacy;
* ready → integrated, reconciliation_required or legacy;
* integrated → suspended. **Integrated never goes straight back to legacy.**
* suspended → integrated, or → legacy only with no open request in Finance.

Only `projects:finance-mode {slug} {mode} --reason=… --by=…` switches a mode.
Nothing switches one on its own. Each switch is recorded in
`finance_mode_changes` with the checks as they were, and audited.

### Activation checklist (`Readiness`)

Checks that block → ready and ready → integrated:

| Check | Blocks | Clear it by |
|---|---|---|
| finance_connected | yes | the platform issues Finance event tokens for the organization |
| legacy_commitments: approved, unpaid, not handed to Finance | yes | pay or release in Projects first |
| open_advances | yes | settle in Projects |
| petty_cash: boxes still in Projects | yes | move into Finance funds |
| unacknowledged_events: outbox events to Finance pending or failed | yes | deliver or settle (projects:finance-sync) |
| sync_exceptions | yes | resolve the FinanceSync exceptions |

Checks that block one switch only:

| Check | Blocks | Note |
|---|---|---|
| integration_enabled | → integrated | `PROJECTS_FINANCE_INTEGRATION` |
| open_requests | suspended → legacy | Finance settles or cancels them |

Informational only:

| Check | What it tells you |
|---|---|
| pending_approvals | they will go to Finance when approved |
| inbox_rejections (30 days) | outcomes Finance sent that were refused |
| other_currencies | Finance converts at its own rates |
| existing_records | payments already in Projects; they stay as they are |

## 8. Organizations without Finance

They stay in legacy: nothing changes for them. Payments are recorded in
Projects. The outbox skips organizations without Finance. Figures carry
`provenance.paidStatus = recorded` and `source = projects`, which labels them
"as recorded, not verified by Finance". Projects keeps no second ledger: it
records the payment fact, not journals.

## 9. Blockers (documented, not worked around)

1. **The platform cannot mint Finance → Projects tokens.** In central,
   `EventTokenIssuer::issue` only issues for a `SharedModule` audience;
   Projects is a `Product`. A central change is needed: allow
   `aud = projects` when the source is Finance and the organization is
   entitled to both. Not made here, because it changes the platform's trust
   model and needs approval.
2. **Finance has no handler for `finance.spending.*`.** Its EventController
   answers 422 `unsupported_event`. Finance would need:
   * a payment request created from the event, idempotent on
     `idempotency_key`;
   * its own outbox to Projects emitting the §4 outcomes with a per-request
     sequence.

   Not built, because Finance and Payroll are out of scope here.
3. Until both exist, `PROJECTS_FINANCE_INTEGRATION` stays off, so the
   integrated check blocks every switch to integrated.

## 10. Known gaps in integrated mode (decisions in §11)

* The Pay action is hidden (expense list, approvals page) and refused
  server-side where Finance owns payment. Other pages that list commitments
  (budget, grant) still show the expense as committed until Finance pays,
  which is correct; they do not yet show Finance's request status.
* Staff advances and their retirements still record payments in Projects.
  Readiness requires none open at switch-over, but new ones could be
  requested afterwards.
* A request rejected by Finance leaves the expense approved (a commitment).
  Reconciliation flags it as `rejected_open`. There is no resubmit (revision
  2) action yet.

## 11. Cutover sequence (not started)

1. Central: allow Finance → Projects tokens (blocker 1). Deploy.
2. Finance: the `finance.spending.*` handlers, its outbox to Projects with
   sequence numbers, and `platform-client.modules.projects` set to the
   Projects events URL. Deploy.
3. Projects: decide advances and the resubmit action; show Finance's
   request status on the expense. Deploy with `PROJECTS_FINANCE_INTEGRATION=false`.
4. Staging, with a test organization:
   * enable the flag;
   * walk legacy → reconciliation_required → ready → integrated;
   * run every outcome end to end, including duplicates, out-of-order
     delivery and a reversal;
   * run `projects:finance-reconcile` until it is clean.
5. Production: enable the flag, then switch **one** pilot organization after
   its checks pass. Watch finance-sync and finance-reconcile daily.
   Rollback means switching it to suspended, never back to legacy while
   requests are open.
6. Only then plan moving payment ownership for the remaining organizations.
