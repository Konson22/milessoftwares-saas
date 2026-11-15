# Finance ↔ Projects contract (Phases 3B and 3C)

Status: **built on both sides and verified locally end to end (§18), switched
off.** No organization is integrated and nothing is deployed. Central issues
Finance → Projects tokens (§9); Finance handles `finance.spending.*` and reports
every outcome (§12). This document is the contract both sides build to; ADR-0017
§6 is the decision it implements.

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
* `finance.advance.requested`: staff advances are not integrated; organizations using them stay out of integrated mode (§15).
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

**Cancellation crossing Finance (Phase 3C).** When Finance reports
accepted, approved or failed after Projects asked to cancel, its facts
(reference, approved amount) are kept, but the request stays
`cancellation_requested` until Finance answers the cancellation. Finance
answers with cancelled, rejected or, if it paid anyway, a payment. Nothing
is committed while a cancellation is asked for.

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

## 9. Blockers (resolved in Phase 3C)

1. **Finance → Projects tokens.** Central's `EventTokenIssuer` keeps its
   rule (an audience must be a shared module), with one listed exception,
   `MODULE_TO_PRODUCT = ['finance' => ['projects']]`:
   * only Finance may send, and only to Projects;
   * the organization must be entitled to both, and active;
   * the token is the usual one (RS256, 300 s; `aud` projects, `sub`
     finance, `org`).

   Any other product-to-product pair is refused (`unknown_module`), and no
   general service token exists. Tests: `ModuleToProductTokenTest`.
2. **Finance's handlers.** `finance.spending.submitted` and
   `finance.spending.cancelled` are handled by `App\Finance\ProjectSpending`
   (§12), which reports back through Finance's outbox to
   `platform-client.modules.projects` (`PLATFORM_PROJECTS_EVENTS_URL`).
3. `PROJECTS_FINANCE_INTEGRATION` stays **off** in every deployed
   environment. No organization is integrated.

## 10. Known gaps in integrated mode

* Payment failures: Finance has no failed-payment state, so
  `projects.finance_payment.failed` is never sent (§12). Projects handles it
  if it ever comes.
* Corrections: Finance corrects by reversal, so
  `projects.finance_transaction.corrected` is never sent. Projects handles
  it as references only.
* Several payments under one approval: a Finance payment request is paid
  in one disbursement. Projects accepts several payments, but Finance sends
  one.
* Budget and grant pages show the expense as committed. Only the expenses
  page shows Finance's status for now.

## 11. Cutover sequence

Steps 1–3 are built (not deployed); step 4 was rehearsed locally (§18).

1. Central: Finance → Projects tokens. Deploy.
2. Finance: the handlers, the Project requests page, and
   `PLATFORM_PROJECTS_EVENTS_URL` set. Deploy.
3. Projects:
   * the revision index migration (`2026_11_17_000200`);
   * resubmission;
   * advances and petty cash refused where Finance pays;
   * the order gate.

   Deploy with `PROJECTS_FINANCE_INTEGRATION=false`.
4. Staging, with a test organization:
   * run `ops/e2e/finance-projects/run.php`'s scenarios against the deployed
     apps;
   * walk the modes;
   * run `projects:finance-reconcile` until it is clean.
5. Production: enable the flag, then switch **one** pilot organization,
   without staff advances (§15), after its checks pass. Watch
   finance-sync and finance-reconcile daily. Rollback means suspended,
   never legacy, while requests are open.
6. Only then plan payment ownership for the other organizations. Historical
   payments are not migrated.

## 12. How Finance handles a request (Phase 3C)

`App\Finance\ProjectSpending`, table `project_spending_requests` (one row
per request revision). Finance's own approval engine is reused: no second
one exists.

| Projects sends / Finance does | Finance row | Finance → Projects |
|---|---|---|
| `finance.spending.submitted` arrives (validated, §12.1) | received | nothing: arrival is not acceptance |
| A Finance person takes it in on **Project requests**: a payment request (expense, from a bank account in the request's currency, amount ≤ requested, payee = supplier, project dimension, reference `projects:{request_id}`) | prepared | `finance_request.accepted` (finance_reference = the PR number) |
| The payment request is approved (review/approval rules, limit, separation of duties) | approved | `finance_request.approved` (approved_minor = the PR amount) |
| Raised below the approval limit (approved as raised) | approved | accepted, then approved |
| The payment request is disbursed (journal posted) | paid | `finance_payment.completed` (payment_id = PR id, paid_on = journal date, journal_reference) |
| The payment journal is reversed | reversed | `finance_payment.reversed` (the full amount, memo as reason) |
| Refused on Project requests | rejected | `finance_request.rejected` (reason) |
| The payment request is rejected or withdrawn | rejected | `finance_request.rejected` (the PR's note, or "Withdrawn in Finance.") |
| `finance.spending.cancelled` while received | cancelled | `finance_request.cancelled` |
| `finance.spending.cancelled` once a PR exists | cancellation flagged ("Projects asked to cancel") | nothing until Finance withdraws or rejects the PR: then `cancelled` |

**Projects' approval is operational only.** Nothing is approved or paid
because Projects approved it: every request waits for a Finance person, and
every payment for Finance's approval (`payment_approval_threshold_minor`).

### 12.1 What Finance refuses (422, never retried)

* A sender other than Projects.
* A payload organization that is not the envelope's.
* Missing or ill-typed references (strict integers for `amount_minor` and
  `version`), a currency that is not ISO 4217, and `contract_version` ≠ 1.
* An `idempotency_key` that is not `finance-request:{request_id}:r{version}`.
* `project.id` ≠ `project_id`.
* A `request_id` used before with other content.
* A revision not newer than one Finance has for the correlation, or a new
  revision while an earlier one is still open or paid.

A cancellation for a request Finance has not received yet is answered 503
(retried), so it waits for the submission.

### 12.2 Outcome fields

Every outcome carries:
* organization_id, request_id and correlation_id;
* sequence (per request, from 1, no gaps; incremented in the same
  transaction as the change);
* Finance's own status and finance_reference;
* amount and currency where money is involved.

Each outcome is written to the outbox in the transaction that changed
Finance's record. Delivery never creates an outcome.

## 13. Amounts (Projects, `FinanceRequest`)

| Value | Rule |
|---|---|
| requested | `amount_minor` |
| approved | `approved_minor`, null until Finance approves; may be less |
| paid / reversed | sums of Finance's payment and reversal events |
| net paid | paid − reversed |
| outstanding authorized | approved − net paid, only while approved, partially_paid or failed; 0 otherwise |
| awaiting Finance | requested, while submitted or accepted; 0 otherwise |
| open commitment | awaiting Finance + outstanding authorized |

`ProjectFinance` counts the latest revision's open commitment as
committed, in place of the expense amount. It counts net paid over every
revision as paid. So:
* a refused, cancelled, reversed or cancellation-asked request commits
  nothing;
* the part Finance did not approve is not committed;
* an approval is never counted as paid.

Tested in `FinanceIntegrationTest` and in E2E scenarios 04, 05, 11 and 13.

## 14. Rejection and resubmission

* Finance's refusal (and its reason) stays on the rejected revision. The
  expenses page shows it.
* Someone with `finance.expenses.approve`, who is not the requester, may
  send the spending again. This is allowed only when the latest revision
  was refused or reversed, and the expense is still approved. They give an
  amount (≤ approved minus what is already net paid) and a note.
* The new revision has a new request id, `version` n+1 and the same
  `correlation_id` (unique per revision: migration `2026_11_17_000200`).
  It carries `supersedes_request_id` and `revision_note`.
* Finance refuses an older or concurrent revision (§12.1), so an obsolete
  revision cannot be paid.
* Reversible: a revision is cancelled like any request.

## 15. Staff advances (assessment)

Advances are not integrated: Finance has no advance-request handler. A
second disbursement path is not allowed, so:
* where Finance owns payment, `StaffAdvances::request`, `approve` and
  `disburse` are refused, and so are new petty cash boxes and payments;
* Readiness check `advances_not_integrated` blocks ready, integrated and
  resuming for an organization with an advance policy set.

Advances already paid out are still accounted for as before (retirement,
returns, write-offs). History is kept and not migrated.

The integration this needs later: Projects requests → Finance approves and
issues → Finance reports → Projects shows the advance.

## 16. Procurement (decision)

Inspected:
* purchase requests and orders (`App\Actions\Procurement`);
* the order's expense (`Spending::request` when the order is raised,
  approved in Projects);
* `requirePayable`: received in full and invoiced.

Decision:
* **No dedicated commitment events.** An approved order is a Projects
  commitment, as any approved spending.
* **No request before the order is payable.** In integrated mode the
  order's spending goes to Finance as `finance.spending.submitted` only once
  everything is received and the invoice is recorded
  (`FinanceRequests::submitIfPayable`, called on receipt and on invoice).
  Without this, Finance could pay an order before the goods arrived.
* Approving an order is never payment. `finance.commitment.*` stays
  specified, not emitted (§3), until Procurement work needs Finance to see
  commitments early.

## 17. Reconciliation and manual recovery

`projects:finance-reconcile [--organization=] [--json]` is read-only. Its
findings are listed at the top of `Reconciliation`. Phase 3C added:
* `decision_delayed`: taken in, not decided in time;
* `unresolved_reversal`: reversed in full, still approved, not sent again;
* earlier revisions are ignored once a newer one exists.

`projects:finance-sync` covers Projects' legacy payment events.
Permanently refused events (401, 403, 409, 422, or given up after 8
attempts) are **never retried by themselves**.

Manual recovery, one event at a time:

1. Find it. Projects side: `projects:finance-reconcile`
   (`missing_in_finance`, rejected outcomes). Finance side:
   `platform_outbox` rows with audience `projects` and status `failed`,
   with `last_error`.
2. Find the cause in the receiver's log or in `finance_inbox_rejections`
   (reason, detail). Fix the cause, not the event. Examples:
   * a key not published;
   * an organization not entitled or not integrated;
   * a bug, fixed in a release.
3. If the event itself is wrong (wrong amount, wrong organization), do
   **not** resend it. Correct it at the source: Finance reverses or
   withdraws; Projects cancels or sends a new revision. The source then
   writes a new event.
4. If the event is right and the cause is fixed:
   `php artisan platform:outbox-requeue <id> --reason="…" --by=<who>`. The
   event is resent unchanged (same transaction id and payload), so the
   receiver applies it at most once. The command is logged and refuses
   anything that is not `failed`.
5. A missing earlier outcome (503) needs nothing: the outbox retries it.
   Out-of-order outcomes apply when the gap fills (E2E 07–08).
6. Run reconcile again until it is clean.

## 18. End-to-end verification (local, Phase 3C)

`php ops/e2e/finance-projects/run.php` runs the real apps:
* Central on :8911, Finance on :8912 and Projects on :8913, as three PHP
  servers;
* each on its own PostgreSQL database (`e2e_central`, `e2e_finance`,
  `e2e_projects`, recreated every run);
* organizations provisioned by Central calling each product;
* event tokens signed by Central;
* every event over HTTP.

People's steps run as the apps' own actions in CLI drivers: the expense
in Projects; take-in, approval, disbursement and reversal in Finance.
HTTP permission middleware is therefore not exercised there; feature tests
cover it. It never touches the development databases.

Scenarios:
* submission;
* duplicate delivery, both ways;
* acceptance, approval and payment;
* out-of-order delivery and missing-sequence recovery;
* currency and amount mismatch;
* partial approval;
* reversal and revision 2;
* cancellation while processing;
* cross-tenant token;
* forged and expired tokens;
* revoked entitlement;
* delivery failure and recovery.

Payment failure (scenario 12) is not produced by Finance (§10). Payloads
are checked against `contracts/events` (required keys, no extra keys).
Staging and production were not exercised.
