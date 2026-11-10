<?php

namespace App\Enums;

/**
 * What a project's money is planned for and spent on. Labour is costed from
 * time logged; the rest from expenses.
 */
enum CostCategory: string
{
    case Labour = 'labour';
    case Materials = 'materials';
    case Equipment = 'equipment';
    case Travel = 'travel';
    case Subcontract = 'subcontract';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Labour => 'Labour',
            self::Materials => 'Materials',
            self::Equipment => 'Equipment',
            self::Travel => 'Travel',
            self::Subcontract => 'Subcontractors',
            self::Other => 'Other',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(bool $withLabour = true): array
    {
        $cases = array_filter(self::cases(), fn (self $category) => $withLabour || $category !== self::Labour);

        return array_values(array_map(fn (self $category) => ['value' => $category->value, 'label' => $category->label()], $cases));
    }
}
