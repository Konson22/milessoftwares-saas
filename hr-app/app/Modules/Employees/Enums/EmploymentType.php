<?php

namespace App\Modules\Employees\Enums;

/**
 * The kind of contract someone works under. The employee carries the type
 * of their current contract.
 */
enum EmploymentType: string
{
    case Permanent = 'permanent';
    case FixedTerm = 'fixed_term';
    case Casual = 'casual';
    case Internship = 'internship';
    case Consultancy = 'consultancy';

    public function label(): string
    {
        return match ($this) {
            self::Permanent => 'Permanent',
            self::FixedTerm => 'Fixed term',
            self::Casual => 'Casual',
            self::Internship => 'Internship',
            self::Consultancy => 'Consultancy',
        };
    }

    /**
     * Whether a contract of this kind must say when it ends.
     */
    public function hasEndDate(): bool
    {
        return in_array($this, [self::FixedTerm, self::Internship, self::Consultancy], true);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $type) => ['value' => $type->value, 'label' => $type->label()], self::cases());
    }
}
