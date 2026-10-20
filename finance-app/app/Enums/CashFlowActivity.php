<?php

namespace App\Enums;

/**
 * Where a balance sheet account's movements show in the cash flow statement.
 */
enum CashFlowActivity: string
{
    case Operating = 'operating';
    case Investing = 'investing';
    case Financing = 'financing';

    public function label(): string
    {
        return ucfirst($this->value).' activities';
    }
}
