<?php

namespace App\Enums;

/**
 * Why a storekeeper changed stock by hand. Kept on every adjustment.
 */
enum AdjustmentReason: string
{
    case CountCorrection = 'count_correction';
    case Damaged = 'damaged';
    case Expired = 'expired';
    case TheftOrLoss = 'theft_or_loss';
    case ReturnedToSupplier = 'returned_to_supplier';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CountCorrection => 'Count correction',
            self::Damaged => 'Damaged',
            self::Expired => 'Expired',
            self::TheftOrLoss => 'Theft or loss',
            self::ReturnedToSupplier => 'Returned to supplier',
            self::Other => 'Other',
        };
    }
}
