<?php

namespace App\Platform;

use App\Models\Workspace;
use App\Modules\Leave\Models\LeaveType;
use Miles\PlatformClient\Api\Organization;
use Miles\PlatformClient\Provisioning\ProvisionsTenants;

/**
 * Runs when the platform provisions an organization for this product
 * (POST /platform/tenants), inside the tenant and a transaction. Create
 * whatever a new workspace needs here; it must be safe to run again.
 */
class SetUpWorkspace implements ProvisionsTenants
{
    /**
     * Kinds of leave a new workspace starts with; HR changes them in
     * Leave settings to match its policy.
     *
     * @var list<array{name: string, days_per_year: int, paid: bool, allow_negative: bool, max_carry_over: int}>
     */
    public const LEAVE_TYPES = [
        ['name' => 'Annual leave', 'days_per_year' => 21, 'paid' => true, 'allow_negative' => false, 'max_carry_over' => 5],
        ['name' => 'Sick leave', 'days_per_year' => 14, 'paid' => true, 'allow_negative' => false, 'max_carry_over' => 0],
        ['name' => 'Unpaid leave', 'days_per_year' => 0, 'paid' => false, 'allow_negative' => true, 'max_carry_over' => 0],
    ];

    public function provision(Organization $organization): void
    {
        $workspace = Workspace::current() ?? new Workspace;

        $workspace->fill([
            'slug' => $organization->slug,
            'display_name' => $organization->displayName,
            'currency' => $organization->defaultCurrency ?? 'USD',
            'timezone' => $organization->timezone ?? 'UTC',
        ])->save();

        // Only for a workspace with none: HR's own choices are never redone.
        if (! LeaveType::query()->exists()) {
            foreach (self::LEAVE_TYPES as $type) {
                LeaveType::query()->create($type);
            }
        }
    }
}
