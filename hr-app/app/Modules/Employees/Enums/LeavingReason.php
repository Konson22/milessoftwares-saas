<?php

namespace App\Modules\Employees\Enums;

/**
 * Why someone stopped working for the organization. Kept on the employee
 * and on their "left" employment record.
 */
enum LeavingReason: string
{
    case Resigned = 'resigned';
    case Dismissed = 'dismissed';
    case ContractEnded = 'contract_ended';
    case Redundancy = 'redundancy';
    case Retired = 'retired';
    case Deceased = 'deceased';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Resigned => 'Resigned',
            self::Dismissed => 'Dismissed',
            self::ContractEnded => 'Contract ended',
            self::Redundancy => 'Redundancy',
            self::Retired => 'Retired',
            self::Deceased => 'Deceased',
            self::Other => 'Other',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $reason) => ['value' => $reason->value, 'label' => $reason->label()], self::cases());
    }
}
