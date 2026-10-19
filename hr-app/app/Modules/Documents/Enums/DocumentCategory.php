<?php

namespace App\Modules\Documents\Enums;

/**
 * What a staff document is. Identity documents (passport, national ID)
 * carry the same numbers as the personal details, so they need the
 * personal permissions as well as the documents ones.
 */
enum DocumentCategory: string
{
    case Contract = 'contract';
    case Identity = 'identity';
    case WorkPermit = 'work_permit';
    case Licence = 'licence';
    case Certificate = 'certificate';
    case Letter = 'letter';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Contract => 'Contract',
            self::Identity => 'Identity document',
            self::WorkPermit => 'Work permit',
            self::Licence => 'Professional licence',
            self::Certificate => 'Certificate or qualification',
            self::Letter => 'Letter',
            self::Other => 'Other',
        };
    }

    public function restricted(): bool
    {
        return $this === self::Identity;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $category) => ['value' => $category->value, 'label' => $category->label()], self::cases());
    }
}
