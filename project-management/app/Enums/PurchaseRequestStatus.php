<?php

namespace App\Enums;

/**
 * Where a purchase request stands. After Ordered, the order and its spending
 * tell the rest.
 */
enum PurchaseRequestStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Ordered = 'ordered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Awaiting approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Ordered => 'Ordered',
            self::Cancelled => 'Cancelled',
        };
    }
}
