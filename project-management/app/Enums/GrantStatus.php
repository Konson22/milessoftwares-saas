<?php

namespace App\Enums;

/**
 * Where a grant is, from opportunity to closeout.
 */
enum GrantStatus: string
{
    case Opportunity = 'opportunity';
    case Applied = 'applied';
    case Active = 'active';
    case Closed = 'closed';
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Opportunity => 'Opportunity',
            self::Applied => 'Applied',
            self::Active => 'Active',
            self::Closed => 'Closed',
            self::Declined => 'Declined',
        };
    }

    /** Not yet awarded: its amount is what is asked for, and can still change freely. */
    public function isPipeline(): bool
    {
        return $this === self::Opportunity || $this === self::Applied;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $status) => ['value' => $status->value, 'label' => $status->label()], self::cases());
    }
}
