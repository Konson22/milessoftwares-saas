<?php

namespace App\Modules\Sales\Enums;

/**
 * A wholesale order's life: a quotation until the customer confirms it,
 * then delivered in one or more delivery notes. A quotation, or an order
 * nothing was delivered on, can be cancelled; one partly delivered can be
 * closed, leaving the rest undelivered.
 */
enum SalesOrderStatus: string
{
    case Quote = 'quote';
    case Confirmed = 'confirmed';
    case PartlyDelivered = 'partly_delivered';
    case Delivered = 'delivered';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Quote => 'Quotation',
            self::Confirmed => 'Confirmed',
            self::PartlyDelivered => 'Partly delivered',
            self::Delivered => 'Delivered',
            self::Closed => 'Closed short',
            self::Cancelled => 'Cancelled',
        };
    }

    public function canDeliver(): bool
    {
        return in_array($this, [self::Confirmed, self::PartlyDelivered], true);
    }
}
