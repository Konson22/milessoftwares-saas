<?php

namespace App\Enums;

enum VehicleKind: string
{
    case Truck = 'truck';
    case Van = 'van';
    case Pickup = 'pickup';
    case Motorbike = 'motorbike';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Truck => 'Truck',
            self::Van => 'Van',
            self::Pickup => 'Pickup',
            self::Motorbike => 'Motorbike',
            self::Other => 'Other',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $kind) => ['value' => $kind->value, 'label' => $kind->label()], self::cases());
    }
}
