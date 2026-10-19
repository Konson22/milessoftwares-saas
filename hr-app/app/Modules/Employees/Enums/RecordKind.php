<?php

namespace App\Modules\Employees\Enums;

/**
 * What an employment record says happened.
 */
enum RecordKind: string
{
    case Hired = 'hired';
    case Transfer = 'transfer';
    case Promotion = 'promotion';
    case Change = 'change';
    case Left = 'left';

    public function label(): string
    {
        return match ($this) {
            self::Hired => 'Hired',
            self::Transfer => 'Transfer',
            self::Promotion => 'Promotion',
            self::Change => 'Change of job',
            self::Left => 'Left',
        };
    }

    /**
     * The kinds a person moving within the organization can be recorded as.
     *
     * @return list<self>
     */
    public static function moves(): array
    {
        return [self::Transfer, self::Promotion, self::Change];
    }
}
