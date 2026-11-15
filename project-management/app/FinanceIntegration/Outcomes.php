<?php

namespace App\FinanceIntegration;

use App\Audit\Audit;
use App\Models\FinancePayment;
use App\Models\FinanceRequest;
use Carbon\CarbonImmutable;
use Miles\SharedModuleKit\Envelope;

/**
 * Applies what Finance reports about a request (projects.* events, contract
 * docs/finance/finance-projects-contract.md §4). Runs inside the inbox
 * transaction, once per event:
 *
 *   projects.finance_request.accepted      Finance took the request in
 *   projects.finance_request.rejected      Finance refused it
 *   projects.finance_request.approved      Finance will pay (approved_minor)
 *   projects.finance_request.cancelled     Finance confirms a cancellation
 *   projects.finance_payment.completed     money paid (partial or in full)
 *   projects.finance_payment.failed        a payment attempt failed
 *   projects.finance_payment.reversed      money paid was taken back
 *   projects.finance_transaction.corrected Finance corrected its posting
 *                                          (references only; no status)
 *
 * Each event carries the request's id and correlation id and Finance's
 * sequence number for that request. Outcomes apply strictly in sequence:
 * a gap waits (503, retried), an older number is refused. Amounts never
 * exceed what was asked or approved, in the request's currency. Nothing
 * here touches the expense, the books or any other record: Projects'
 * figures read the request (ProjectFinance).
 */
final class Outcomes
{
    public const TYPES = [
        'projects.finance_request.accepted',
        'projects.finance_request.rejected',
        'projects.finance_request.approved',
        'projects.finance_request.cancelled',
        'projects.finance_payment.completed',
        'projects.finance_payment.failed',
        'projects.finance_payment.reversed',
        'projects.finance_transaction.corrected',
    ];

    /**
     * @throws OutcomeRefused
     * @throws OutcomeNotYet
     */
    public function apply(Envelope $event): void
    {
        if (! in_array($event->eventType, self::TYPES, true)) {
            throw OutcomeRefused::invalid('unsupported_event', "{$event->eventType} is not an outcome Projects takes.");
        }
        if ($event->schemaVersion !== 1) {
            throw OutcomeRefused::invalid('unsupported_version', "Schema version {$event->schemaVersion} of {$event->eventType} is not supported.");
        }

        $p = $event->payload;
        if (array_key_exists('organization_id', $p) && $p['organization_id'] !== $event->organizationId) {
            throw OutcomeRefused::invalid('organization_mismatch', 'The payload names another organization than the envelope.');
        }
        $requestId = self::string($p, 'request_id');
        $request = $requestId !== null && preg_match('/^[0-9a-f-]{36}$/i', $requestId) === 1
            ? FinanceRequest::query()->lockForUpdate()->find($requestId)
            : null;
        if ($request === null) {
            throw OutcomeRefused::invalid('unknown_request', 'No request of this organization has that request_id.');
        }
        if (self::string($p, 'correlation_id') !== $request->correlation_id) {
            throw OutcomeRefused::invalid('correlation_mismatch', 'The correlation_id is not the request\'s.');
        }

        $sequence = $p['sequence'] ?? null;
        if (! is_int($sequence) || $sequence < 1) {
            throw OutcomeRefused::invalid('invalid_payload', 'sequence must be a positive integer.');
        }
        if ($sequence <= $request->finance_sequence) {
            throw OutcomeRefused::conflict('stale_sequence', "Outcome {$sequence} is older than the last applied ({$request->finance_sequence}).");
        }
        if ($sequence > $request->finance_sequence + 1) {
            throw new OutcomeNotYet("Outcome {$sequence} arrived before ".($request->finance_sequence + 1).'.');
        }

        $currency = self::string($p, 'currency');
        if ($currency !== null && $currency !== $request->currency) {
            throw OutcomeRefused::invalid('currency_mismatch', "The request is in {$request->currency}, not {$currency}.");
        }

        $from = $request->status;
        $facts = [];
        $changes = [];

        switch ($event->eventType) {
            case 'projects.finance_request.accepted':
                $reference = self::string($p, 'finance_reference');
                if ($reference === null || strlen($reference) > 100) {
                    throw OutcomeRefused::invalid('invalid_payload', 'finance_reference is required (at most 100 characters).');
                }
                if (FinanceRequest::query()->where('finance_reference', $reference)->whereKeyNot($request->id)->exists()) {
                    throw OutcomeRefused::conflict('duplicate_reference', 'Another request already has that finance_reference.');
                }
                $to = RequestStatus::Accepted;
                $changes['finance_reference'] = $reference;
                $facts['finance_reference'] = $reference;
                break;

            case 'projects.finance_request.rejected':
                $to = RequestStatus::Rejected;
                $facts['reason'] = self::reason($p);
                break;

            case 'projects.finance_request.approved':
                $approved = $p['approved_minor'] ?? null;
                if (! is_int($approved) || $approved < 1 || $approved > $request->amount_minor) {
                    throw OutcomeRefused::invalid('amount_mismatch', 'approved_minor must be between 1 and the amount requested.');
                }
                $to = RequestStatus::Approved;
                $changes['approved_minor'] = $approved;
                $facts['approved_minor'] = $approved;
                break;

            case 'projects.finance_request.cancelled':
                $to = RequestStatus::Cancelled;
                break;

            case 'projects.finance_payment.completed':
                [$to, $changes, $facts] = $this->paid($request, $p);
                break;

            case 'projects.finance_payment.failed':
                $to = RequestStatus::Failed;
                $facts['reason'] = self::reason($p);
                break;

            case 'projects.finance_payment.reversed':
                [$to, $changes, $facts] = $this->reversed($request, $p);
                break;

            default: // projects.finance_transaction.corrected
                $to = $from;
                $facts = $this->corrected($p);
                break;
        }

        // A cancellation Projects asked for crossed with Finance taking the
        // request in or approving it: Finance's facts are kept, the request
        // stays waiting for Finance's answer to the cancellation.
        if ($from === RequestStatus::CancellationRequested && in_array($to, [RequestStatus::Accepted, RequestStatus::Approved, RequestStatus::Failed], true)) {
            $facts['while_cancellation_requested'] = $to->value;
            $to = RequestStatus::CancellationRequested;
        }

        $correction = $event->eventType === 'projects.finance_transaction.corrected';
        if (! $correction && ! isset($facts['while_cancellation_requested']) && ! $from->allows($to)) {
            throw OutcomeRefused::conflict('invalid_transition', "A request {$from->value} cannot become {$to->value}.");
        }

        $at = CarbonImmutable::instance($event->occurredAt);
        FinanceRequests::record($request, $from, $to, $event->eventType, 'finance', $event->eventId, $sequence, $facts, $at);
        $request->forceFill([...$changes, 'status' => $to, 'finance_sequence' => $sequence, 'last_event_at' => $at])->save();
        Audit::record('finance_request.'.substr($event->eventType, strlen('projects.')), $request, ['from' => $from->value, 'to' => $to->value, 'sequence' => $sequence, ...$facts], $request->organization_id);
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array{RequestStatus, array<string, mixed>, array<string, mixed>}
     */
    private function paid(FinanceRequest $request, array $p): array
    {
        [$paymentId, $amount] = $this->payment($p);
        $paidOn = self::date($p, 'paid_on');
        $journal = self::string($p, 'journal_reference');
        $limit = $request->approved_minor ?? $request->amount_minor;

        if (FinancePayment::query()->where('finance_payment_id', $paymentId)->exists()) {
            throw OutcomeRefused::conflict('duplicate_payment', "Payment {$paymentId} is already recorded.");
        }
        if ($request->paid_minor + $amount > $limit) {
            throw OutcomeRefused::invalid('amount_mismatch', 'Payments would exceed the amount approved.');
        }

        FinancePayment::query()->create([
            'finance_request_id' => $request->id,
            'finance_payment_id' => $paymentId,
            'currency' => $request->currency,
            'amount_minor' => $amount,
            'paid_on' => $paidOn,
            'status' => FinancePayment::COMPLETED,
            'journal_reference' => $journal,
        ]);

        $paid = $request->paid_minor + $amount;
        $net = $paid - $request->reversed_minor;

        return [
            $net >= $limit ? RequestStatus::Paid : RequestStatus::PartiallyPaid,
            ['paid_minor' => $paid],
            ['payment_id' => $paymentId, 'amount_minor' => $amount, 'paid_on' => $paidOn->toDateString(), 'journal_reference' => $journal],
        ];
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array{RequestStatus, array<string, mixed>, array<string, mixed>}
     */
    private function reversed(FinanceRequest $request, array $p): array
    {
        [$paymentId, $amount] = $this->payment($p);
        $payment = FinancePayment::query()->lockForUpdate()->where('finance_request_id', $request->id)->where('finance_payment_id', $paymentId)->first();
        if ($payment === null) {
            throw OutcomeRefused::invalid('unknown_payment', "No payment {$paymentId} is recorded for this request.");
        }
        if ($payment->reversed_minor + $amount > $payment->amount_minor) {
            throw OutcomeRefused::invalid('amount_mismatch', 'The reversal exceeds what is left of the payment.');
        }

        $payment->forceFill([
            'reversed_minor' => $payment->reversed_minor + $amount,
            'status' => $payment->reversed_minor + $amount === $payment->amount_minor ? FinancePayment::REVERSED : FinancePayment::COMPLETED,
        ])->save();

        $reversed = $request->reversed_minor + $amount;
        $net = $request->paid_minor - $reversed;

        return [
            $net === 0 ? RequestStatus::Reversed : RequestStatus::PartiallyPaid,
            ['reversed_minor' => $reversed],
            ['payment_id' => $paymentId, 'amount_minor' => $amount, 'reason' => self::reason($p), 'journal_reference' => self::string($p, 'journal_reference')],
        ];
    }

    /**
     * A posting correction in Finance: references are updated, amounts and
     * status never (an amount changes by reversal and a new payment).
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function corrected(array $p): array
    {
        $journal = self::string($p, 'journal_reference');
        if ($journal === null) {
            throw OutcomeRefused::invalid('invalid_payload', 'journal_reference is required.');
        }
        if (array_key_exists('amount_minor', $p)) {
            throw OutcomeRefused::invalid('amount_mismatch', 'A correction does not change amounts: reverse and pay again.');
        }

        $paymentId = self::string($p, 'payment_id');
        if ($paymentId !== null) {
            $payment = FinancePayment::query()->where('finance_payment_id', $paymentId)->first();
            if ($payment === null) {
                throw OutcomeRefused::invalid('unknown_payment', "No payment {$paymentId} is recorded.");
            }
            $payment->forceFill(['journal_reference' => $journal])->save();
        }

        return ['payment_id' => $paymentId, 'journal_reference' => $journal, 'note' => self::reason($p)];
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array{string, int}
     */
    private function payment(array $p): array
    {
        $paymentId = self::string($p, 'payment_id');
        $amount = $p['amount_minor'] ?? null;
        if ($paymentId === null || strlen($paymentId) > 100) {
            throw OutcomeRefused::invalid('invalid_payload', 'payment_id is required (at most 100 characters).');
        }
        if (! is_int($amount) || $amount < 1) {
            throw OutcomeRefused::invalid('amount_mismatch', 'amount_minor must be a positive integer.');
        }
        if (self::string($p, 'currency') === null) {
            throw OutcomeRefused::invalid('invalid_payload', 'currency is required with an amount.');
        }

        return [$paymentId, $amount];
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private static function string(array $p, string $key): ?string
    {
        return isset($p[$key]) && is_string($p[$key]) && $p[$key] !== '' ? $p[$key] : null;
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private static function reason(array $p): ?string
    {
        $reason = self::string($p, 'reason') ?? self::string($p, 'note');

        return $reason === null ? null : mb_substr($reason, 0, 255);
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private static function date(array $p, string $key): CarbonImmutable
    {
        $value = self::string($p, $key);
        $date = $value !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? CarbonImmutable::createFromFormat('Y-m-d', $value) : null;
        if (! $date instanceof CarbonImmutable) {
            throw OutcomeRefused::invalid('invalid_payload', "{$key} must be a date (YYYY-MM-DD).");
        }

        return $date->startOfDay();
    }
}
