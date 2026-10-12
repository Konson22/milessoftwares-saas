<?php

namespace App\Enums;

enum PrescriptionStatus: string
{
    case Recorded = 'recorded';
    case Verified = 'verified';
    case Dispensed = 'dispensed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Recorded => 'Waiting for a pharmacist',
            self::Verified => 'Verified',
            self::Dispensed => 'Dispensed',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }
}
