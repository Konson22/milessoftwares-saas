<?php

namespace App\Enums;

enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Ordered = 'ordered';
    case PartlyReceived = 'partly_received';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function canReceive(): bool
    {
        return in_array($this, [self::Ordered, self::PartlyReceived], true);
    }
}
