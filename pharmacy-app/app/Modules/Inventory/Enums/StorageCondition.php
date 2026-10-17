<?php

namespace App\Modules\Inventory\Enums;

/**
 * How an item must be kept.
 */
enum StorageCondition: string
{
    case Room = 'room';
    case Cool = 'cool';
    case Refrigerated = 'refrigerated';
    case Frozen = 'frozen';

    public function label(): string
    {
        return match ($this) {
            self::Room => 'Room temperature (below 25 °C)',
            self::Cool => 'Cool (8–15 °C)',
            self::Refrigerated => 'Refrigerate (2–8 °C)',
            self::Frozen => 'Frozen (below −15 °C)',
        };
    }

    /**
     * Short, for badges at the counter and on stock.
     */
    public function short(): string
    {
        return match ($this) {
            self::Room => 'Room temperature',
            self::Cool => 'Keep cool',
            self::Refrigerated => 'Refrigerate',
            self::Frozen => 'Keep frozen',
        };
    }
}
