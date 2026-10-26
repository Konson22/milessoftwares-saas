<?php

namespace App\Enums;

/**
 * What a trip's money went on.
 */
enum TripCostCategory: string
{
    case Fuel = 'fuel';
    case Tolls = 'tolls';
    case Allowance = 'allowance';
    case Repairs = 'repairs';
    case Loading = 'loading';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Fuel => 'Fuel',
            self::Tolls => 'Tolls and permits',
            self::Allowance => 'Driver allowance',
            self::Repairs => 'Repairs on the road',
            self::Loading => 'Loading and offloading',
            self::Other => 'Other',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $category) => ['value' => $category->value, 'label' => $category->label()], self::cases());
    }
}
