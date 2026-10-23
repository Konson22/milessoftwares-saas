<?php

namespace App\Modules\Leave\Enums;

/**
 * Where a leave request stands. Only approved requests use up days;
 * pending ones are held against what is still available.
 */
enum LeaveStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for a decision',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Requests that hold days: they block overlapping requests too.
     *
     * @return list<string>
     */
    public static function holding(): array
    {
        return [self::Pending->value, self::Approved->value];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $status) => ['value' => $status->value, 'label' => $status->label()], self::cases());
    }
}
