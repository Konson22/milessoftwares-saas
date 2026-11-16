<?php

namespace App\Support;

/**
 * The parts of the product an organization can switch off, all on until it does.
 * Switching one off hides it and refuses its pages; nothing is deleted, and
 * switching it on again brings everything back. Projects and spending are not
 * here: approvals, budgets, tasks and most other parts depend on them.
 */
final class Modules
{
    public const GRANTS = 'grants';

    public const PROCUREMENT = 'procurement';

    /** Petty cash and staff advances; the key predates the books moving to Finance. */
    public const ACCOUNTING = 'accounting';

    public const DOCUMENTS = 'documents';

    public const ALL = [
        self::DOCUMENTS => [
            'name' => 'Documents',
            'description' => 'Policies, contracts, certificates and other records, with every version kept.',
        ],
        self::GRANTS => [
            'name' => 'Grants and funders',
            'description' => 'Funders, grants, payments, reports and the donor portal.',
        ],
        self::PROCUREMENT => [
            'name' => 'Procurement',
            'description' => 'Purchase requests, quotes, orders and goods received.',
        ],
        self::ACCOUNTING => [
            'name' => 'Petty cash and advances',
            'description' => 'Petty cash boxes and staff advances. The books are in Finance, which is told what is paid and received whether or not this is on.',
        ],
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }
}
