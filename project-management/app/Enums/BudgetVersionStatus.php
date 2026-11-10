<?php

namespace App\Enums;

/**
 * A budget version: drafted, submitted for approval (or returned to
 * draft), approved, then superseded when a later version is approved.
 */
enum BudgetVersionStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Awaiting approval',
            self::Approved => 'Approved',
            self::Superseded => 'Superseded',
        };
    }
}
