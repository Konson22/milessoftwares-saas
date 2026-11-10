<?php

namespace App\Enums;

/**
 * Where spending is: requested (not yet counted), approved (committed:
 * the money is promised), paid (spent), or rejected / cancelled (not
 * counted). Each expense is in exactly one, so committed and spent never
 * count the same money twice.
 */
enum ExpenseStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Awaiting approval',
            self::Approved => 'Approved, not paid',
            self::Paid => 'Paid',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Takes from what is left of the budget: committed or spent. */
    public function counts(): bool
    {
        return $this === self::Approved || $this === self::Paid;
    }

    /** Still open: it can be cancelled. */
    public function isOpen(): bool
    {
        return $this === self::Requested || $this === self::Approved;
    }
}
