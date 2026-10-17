<?php

namespace App\Modules\Inventory\Enums;

/**
 * Why stock is destroyed rather than sold.
 */
enum DisposalReason: string
{
    case Expired = 'expired';
    case Damaged = 'damaged';
    case Recalled = 'recalled';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Expired => 'Expired',
            self::Damaged => 'Damaged',
            self::Recalled => 'Recalled',
            self::Other => 'Other',
        };
    }
}
