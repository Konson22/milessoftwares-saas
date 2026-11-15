<?php

namespace App\Finance;

use App\Models\Account;
use App\Models\DimensionValue;
use App\Models\Journal;
use App\Models\PaymentRequest;
use App\Models\ProjectSpendingRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Events\Outbox;
use Miles\SharedModuleKit\Envelope;

/**
 * Spending Projects hands to Finance, and what Finance tells Projects back
 * (docs/finance/finance-projects-contract.md, Phase 3C).
 *
 * In:  finance.spending.submitted  a request is received; nothing is approved,
 *                                  posted or paid because Projects approved it
 *      finance.spending.cancelled  cancelled if not yet taken in; otherwise
 *                                  flagged for the payment request's people
 *
 * Finance's people decide, with Finance's own rules:
 *   prepare()  takes it in as an ordinary expense payment request (bank, amount
 *              up to what was asked), which then goes through review, approval
 *              and disbursement like any other (PaymentRequests)
 *   refuse()   refuses it before it is taken in
 *
 * Out (to Projects, through the platform outbox, in the same transaction as
 * the decision, one sequence number per request):
 *   accepted, approved, rejected, cancelled   (projects.finance_request.*)
 *   completed, reversed                       (projects.finance_payment.*)
 *
 * An outcome is only ever written by the Finance change it reports: never
 * from a delivery result.
 */
final class ProjectSpending
{
    public const AUDIENCE = 'projects';

    public const RECEIVED = 'received';

    public const PREPARED = 'prepared';

    public const APPROVED = 'approved';

    public const PAID = 'paid';

    public const REVERSED = 'reversed';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    /** Not yet decided, or decided and not yet paid. */
    public const OPEN = [self::RECEIVED, self::PREPARED, self::APPROVED];

    /** Projects' cost categories that may be paid through Finance (labour comes from Payroll). */
    public const CATEGORIES = [
        'materials' => Account::PROJECT_MATERIALS,
        'equipment' => Account::PROJECT_EQUIPMENT,
        'travel' => Account::PROJECT_TRAVEL,
        'subcontract' => Account::PROJECT_SUBCONTRACT,
        'other' => Account::OTHER_EXPENSES,
    ];

    public function __construct(
        private readonly Outbox $outbox,
        private readonly ChartOfAccounts $chart,
        private readonly Audit $audit,
    ) {}

    /**
     * finance.spending.submitted v1.
     *
     * @throws ValidationException when the request cannot be taken (422: Projects does not retry it)
     */
    public function received(Envelope $event): void
    {
        $p = $this->validated($event, [
            'status' => ['required', Rule::in(['submitted'])],
            'project' => ['required', 'array'],
            'project.id' => ['required', 'uuid'],
            'project.code' => ['required', 'string', 'max:32'],
            'project.name' => ['required', 'string', 'max:255'],
            'expense_id' => ['required', 'uuid'],
            'category' => ['required', Rule::in(array_keys(self::CATEGORIES))],
            'description' => ['required', 'string', 'max:255'],
            'supplier' => ['nullable', 'string', 'max:255'],
            'grant_reference' => ['nullable', 'string', 'max:64'],
            'approvals' => ['nullable', 'integer', 'min:1'],
        ]);

        if (ProjectSpendingRequest::query()->where('request_id', $p['request_id'])->exists()) {
            throw ValidationException::withMessages(['request_id' => 'This request id was already received under another key.']);
        }

        $revisions = ProjectSpendingRequest::query()->where('correlation_id', $p['correlation_id'])->lockForUpdate()->get();
        if ($revisions->contains(fn (ProjectSpendingRequest $r) => $r->revision >= (int) $p['version'])) {
            throw ValidationException::withMessages(['version' => "Revision {$p['version']} is not newer than the one Finance has: it is not taken as a new request."]);
        }
        if ($revisions->contains(fn (ProjectSpendingRequest $r) => in_array($r->status, self::OPEN, true) || $r->status === self::PAID)) {
            throw ValidationException::withMessages(['version' => 'An earlier revision is still open or was paid: only a rejected or cancelled request is resubmitted.']);
        }

        $request = ProjectSpendingRequest::query()->create([
            'request_id' => $p['request_id'],
            'correlation_id' => $p['correlation_id'],
            'idempotency_key' => $p['idempotency_key'],
            'revision' => (int) $p['version'],
            'project_id' => $p['project']['id'],
            'project_code' => $p['project']['code'],
            'project_name' => $p['project']['name'],
            'expense_id' => $p['expense_id'],
            'category' => $p['category'],
            'description' => $p['description'],
            'supplier' => $p['supplier'] ?? null,
            'grant_reference' => $p['grant_reference'] ?? null,
            'currency' => $p['currency'],
            'amount_minor' => (int) $p['amount_minor'],
            'requested_at' => CarbonImmutable::parse($p['created_at']),
            'status' => self::RECEIVED,
        ]);
        $this->audit->record('project_spending.received', $request, null, ['project' => $request->project_code, 'amount_minor' => $request->amount_minor, 'currency' => $request->currency, 'revision' => $request->revision]);
    }

    /**
     * finance.spending.cancelled v1.
     *
     * @throws NotYetPostable when the request has not arrived yet (Projects retries)
     * @throws ValidationException
     */
    public function cancelled(Envelope $event): void
    {
        $p = $this->validated($event, [
            'status' => ['required', Rule::in(['cancellation_requested'])],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $request = ProjectSpendingRequest::query()->where('request_id', $p['request_id'])->lockForUpdate()->first()
            ?? throw new NotYetPostable('The request being cancelled has not reached Finance yet.');
        if ($request->correlation_id !== $p['correlation_id'] || $request->idempotency_key !== $p['idempotency_key']) {
            throw ValidationException::withMessages(['correlation_id' => 'The cancellation does not match the request Finance has.']);
        }

        $request->forceFill(['cancellation_requested_at' => $request->cancellation_requested_at ?? now(), 'cancellation_reason' => $p['reason'] ?? null])->save();

        // Not yet taken in: cancelled now. Taken in: the payment request's
        // people reject or withdraw it, and it is reported cancelled then.
        // Already paid: Projects has been told; the payment stands.
        if ($request->status === self::RECEIVED) {
            $request->forceFill(['status' => self::CANCELLED])->save();
            $this->emit($request, 'projects.finance_request.cancelled', []);
        }
        $this->audit->record('project_spending.cancellation_requested', $request, null, ['status' => $request->status, 'reason' => $p['reason'] ?? null]);
    }

    /**
     * Takes the request in as an expense payment request paid from $from, for
     * up to the amount asked. Finance's approval limit, review and separation
     * of duties apply as to any request; below the limit it is approved now.
     *
     * @throws ValidationException
     */
    public function prepare(ProjectSpendingRequest $request, Account $from, int $amountMinor, CarbonImmutable $payOn, User $by): PaymentRequest
    {
        return DB::transaction(function () use ($request, $from, $amountMinor, $payOn, $by) {
            $locked = ProjectSpendingRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== self::RECEIVED) {
                throw ValidationException::withMessages(['request' => 'This request has already been decided.']);
            }
            if ($locked->cancellation_requested_at !== null) {
                throw ValidationException::withMessages(['request' => 'Projects asked for this request to be cancelled.']);
            }
            if ($amountMinor < 1 || $amountMinor > $locked->amount_minor) {
                throw ValidationException::withMessages(['amount' => 'Pay at least something and no more than was asked.']);
            }
            if (($from->currency ?? Money::currency()) !== $locked->currency) {
                throw ValidationException::withMessages(['account_id' => "The request is in {$locked->currency}: pay it from an account in {$locked->currency}."]);
            }

            $payment = app(PaymentRequests::class)->submit(PaymentRequest::EXPENSE, $from, $amountMinor, $payOn, "{$locked->project_code}: {$locked->description}", $by, [
                'other_account' => $this->chart->onDemand(self::CATEGORIES[$locked->category]),
                'project' => $this->project($locked),
                'payee' => $locked->supplier,
                // The books' own currency is written as none, as on the form.
                'currency' => $locked->currency === Money::currency() ? null : $locked->currency,
                'reference' => "projects:{$locked->request_id}",
            ]);

            $locked->forceFill(['status' => self::PREPARED, 'payment_request_id' => $payment->id, 'decided_by' => $by->id, 'decided_at' => now()])->save();
            $this->emit($locked, 'projects.finance_request.accepted', []);
            $this->audit->record('project_spending.prepared', $locked, ['status' => self::RECEIVED], ['status' => self::PREPARED, 'payment_request' => $payment->number, 'amount_minor' => $amountMinor], $by);
            if ($payment->status === PaymentRequest::APPROVED) {
                $this->followed($payment, 'approved');
            }

            return $payment;
        });
    }

    /**
     * Refuses the request before it is taken in.
     *
     * @throws ValidationException
     */
    public function refuse(ProjectSpendingRequest $request, string $reason, User $by): void
    {
        DB::transaction(function () use ($request, $reason, $by) {
            $locked = ProjectSpendingRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== self::RECEIVED) {
                throw ValidationException::withMessages(['request' => 'This request has already been decided.']);
            }

            $cancelled = $locked->cancellation_requested_at !== null;
            $locked->forceFill(['status' => $cancelled ? self::CANCELLED : self::REJECTED, 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => mb_substr($reason, 0, 255)])->save();
            $this->emit($locked, $cancelled ? 'projects.finance_request.cancelled' : 'projects.finance_request.rejected', $cancelled ? [] : ['reason' => mb_substr($reason, 0, 255)]);
            $this->audit->record('project_spending.refused', $locked, ['status' => self::RECEIVED], ['status' => $locked->status, 'reason' => $reason], $by);
        });
    }

    /**
     * Called by PaymentRequests, in its transaction, after a step on a payment
     * request: reported to Projects when the request came from there.
     *
     * @param  'approved'|'closed'|'disbursed'  $step
     */
    public function followed(PaymentRequest $payment, string $step): void
    {
        $request = ProjectSpendingRequest::query()->where('payment_request_id', $payment->id)->lockForUpdate()->first();
        if ($request === null) {
            return;
        }

        if ($step === 'approved') {
            $request->forceFill(['status' => self::APPROVED])->save();
            $this->emit($request, 'projects.finance_request.approved', ['approved_minor' => $payment->amount_minor, 'currency' => $request->currency]);

            return;
        }

        if ($step === 'closed') {
            $cancelled = $request->cancellation_requested_at !== null;
            $request->forceFill(['status' => $cancelled ? self::CANCELLED : self::REJECTED, 'decision_note' => $payment->close_note])->save();
            $this->emit($request, $cancelled ? 'projects.finance_request.cancelled' : 'projects.finance_request.rejected', $cancelled ? [] : [
                'reason' => $payment->status === PaymentRequest::WITHDRAWN ? 'Withdrawn in Finance.' : mb_substr((string) ($payment->close_note ?? 'Rejected in Finance.'), 0, 255),
            ]);

            return;
        }

        $journal = Journal::query()->find($payment->journal_id);
        $request->forceFill(['status' => self::PAID])->save();
        $this->emit($request, 'projects.finance_payment.completed', [
            'payment_id' => $payment->id,
            'amount_minor' => $payment->amount_minor,
            'currency' => $request->currency,
            'paid_on' => ($journal === null ? CarbonImmutable::today() : $journal->entry_date)->toDateString(),
            'journal_reference' => $journal?->number,
        ]);
    }

    /**
     * A journal was reversed: when it paid a Projects request, Projects is
     * told the payment was taken back. Runs in the reversal's transaction.
     */
    public function journalReversed(Journal $journal): void
    {
        $payment = PaymentRequest::query()->where('journal_id', $journal->id)->first();
        $request = $payment === null ? null : ProjectSpendingRequest::query()->where('payment_request_id', $payment->id)->lockForUpdate()->first();
        if ($payment === null || $request === null || $request->status !== self::PAID) {
            return;
        }

        $reversal = Journal::query()->find($journal->reversed_by_id);
        $request->forceFill(['status' => self::REVERSED])->save();
        $this->emit($request, 'projects.finance_payment.reversed', [
            'payment_id' => $payment->id,
            'amount_minor' => $payment->amount_minor,
            'currency' => $request->currency,
            'reason' => $reversal === null ? null : mb_substr($reversal->memo, 0, 255),
            'journal_reference' => $reversal?->number,
        ]);
    }

    /**
     * The common references of both request events, checked against the
     * envelope.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validated(Envelope $event, array $rules): array
    {
        if ($event->sourceApp !== 'projects') {
            throw ValidationException::withMessages(['source_app' => 'Only Projects sends spending requests.']);
        }

        $p = Validator::validate($event->payload, [
            'originating_app' => ['required', Rule::in(['projects'])],
            'organization_id' => ['required', 'uuid'],
            'project_id' => ['required', 'uuid'],
            'request_id' => ['required', 'uuid'],
            'correlation_id' => ['required', 'uuid'],
            'idempotency_key' => ['required', 'string', 'max:128'],
            'version' => ['required', 'integer', 'min:1'],
            'contract_version' => ['required', 'integer', Rule::in([1])],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'created_at' => ['required', 'date'],
            ...$rules,
        ]);

        // Amounts and revisions are JSON integers, never strings (the contract's schema).
        foreach (['amount_minor', 'version'] as $key) {
            if (! is_int($event->payload[$key])) {
                throw ValidationException::withMessages([$key => "{$key} must be an integer."]);
            }
        }
        if (strtolower((string) $p['organization_id']) !== $event->organizationId) {
            throw ValidationException::withMessages(['organization_id' => 'The request names another organization than the event.']);
        }
        if ($p['idempotency_key'] !== "finance-request:{$p['request_id']}:r{$p['version']}") {
            throw ValidationException::withMessages(['idempotency_key' => 'The idempotency key does not match the request and revision.']);
        }
        if (isset($p['project']['id']) && $p['project']['id'] !== $p['project_id']) {
            throw ValidationException::withMessages(['project' => 'The project does not match project_id.']);
        }

        return $p;
    }

    /**
     * One outcome for Projects, in the caller's transaction.
     *
     * @param  array<string, mixed>  $extra
     */
    private function emit(ProjectSpendingRequest $request, string $type, array $extra): void
    {
        $sequence = $request->sequence + 1;
        $request->forceFill(['sequence' => $sequence])->save();
        $number = $request->payment_request_id === null ? null : PaymentRequest::query()->whereKey($request->payment_request_id)->value('number');

        $this->outbox->record(self::AUDIENCE, $type, 1, "project-request:{$request->id}:{$sequence}", [
            'organization_id' => $request->organization_id,
            'request_id' => $request->request_id,
            'correlation_id' => $request->correlation_id,
            'sequence' => $sequence,
            'status' => $request->status,
            ...($type === 'projects.finance_request.accepted' ? ['finance_reference' => (string) $number] : ($number === null ? [] : ['finance_reference' => (string) $number])),
            ...$extra,
        ]);
    }

    private function project(ProjectSpendingRequest $request): DimensionValue
    {
        $code = mb_substr(strtoupper((string) preg_replace('/[^A-Za-z0-9_-]/', '', $request->project_code)), 0, 16);

        return DimensionValue::query()->firstOrCreate(
            ['kind' => DimensionValue::PROJECT, 'code' => $code === '' ? 'PROJECT' : $code],
            ['name' => mb_substr($request->project_name, 0, 255), 'active' => true],
        );
    }
}
