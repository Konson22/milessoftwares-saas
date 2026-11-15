<?php

namespace App\FinanceIntegration;

/**
 * Where a request handed to Finance stands (ADR-0017 §6, Phase 3B). Five
 * different decisions are kept apart:
 *
 *   operational approval  Projects (Spending::approve): before the request exists
 *   Finance acceptance    accepted / rejected: Finance takes the request in
 *   financial approval    approved: Finance agrees to pay (it may approve less)
 *   payment execution     partially_paid / paid / failed / reversed
 *   accounting posting    Finance's journals: reported as references
 *                         (journal_reference, transaction corrected), never
 *                         as a status here
 *
 * Only Finance's events move a request past submitted, except a cancellation
 * Projects asks for (cancellation_requested). Every move is recorded
 * (finance_request_transitions); none is undone.
 */
enum RequestStatus: string
{
    case Submitted = 'submitted';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Approved = 'approved';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Failed = 'failed';
    case Reversed = 'reversed';
    case CancellationRequested = 'cancellation_requested';
    case Cancelled = 'cancelled';

    /**
     * The statuses each one may move to. A payment Finance reports after a
     * cancellation was asked for is still recorded: money moved, and
     * reconciliation flags it.
     *
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::Submitted => [self::Accepted, self::Rejected, self::CancellationRequested],
            self::Accepted => [self::Approved, self::Rejected, self::CancellationRequested],
            self::Approved => [self::PartiallyPaid, self::Paid, self::Failed, self::CancellationRequested],
            self::PartiallyPaid => [self::PartiallyPaid, self::Paid, self::Failed, self::Reversed],
            self::Paid => [self::Reversed, self::PartiallyPaid],
            self::Failed => [self::PartiallyPaid, self::Paid, self::CancellationRequested],
            self::Reversed => [self::PartiallyPaid, self::Paid],
            self::CancellationRequested => [self::Cancelled, self::Rejected, self::PartiallyPaid, self::Paid],
            self::Rejected, self::Cancelled => [],
        };
    }

    public function allows(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    /**
     * Still waiting for Finance: switching back to legacy is refused while
     * any request is open.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Submitted, self::Accepted, self::Approved, self::PartiallyPaid, self::Failed, self::CancellationRequested], true);
    }

    /** Money is still owed under the request (a commitment). */
    public function commits(): bool
    {
        return in_array($this, [self::Submitted, self::Accepted, self::Approved, self::PartiallyPaid, self::Failed], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Sent to Finance',
            self::Accepted => 'Taken in by Finance',
            self::Rejected => 'Refused by Finance',
            self::Approved => 'Approved by Finance',
            self::PartiallyPaid => 'Partly paid',
            self::Paid => 'Paid by Finance',
            self::Failed => 'Payment failed',
            self::Reversed => 'Payment reversed',
            self::CancellationRequested => 'Cancellation asked for',
            self::Cancelled => 'Cancelled in Finance',
        };
    }
}
