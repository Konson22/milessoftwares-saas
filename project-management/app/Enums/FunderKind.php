<?php

namespace App\Enums;

/**
 * What kind of funder an organization deals with.
 */
enum FunderKind: string
{
    case Institutional = 'institutional';
    case Multilateral = 'multilateral';
    case Government = 'government';
    case Foundation = 'foundation';
    case Corporate = 'corporate';
    case Individual = 'individual';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Institutional => 'Institutional donor',
            self::Multilateral => 'UN agency or multilateral',
            self::Government => 'Government',
            self::Foundation => 'Foundation or trust',
            self::Corporate => 'Corporate',
            self::Individual => 'Individual',
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
