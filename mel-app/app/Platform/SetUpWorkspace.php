<?php

namespace App\Platform;

use App\Models\Dimension;
use App\Models\DimensionCategory;
use App\Models\Workspace;
use Miles\PlatformClient\Api\Organization;
use Miles\PlatformClient\Provisioning\ProvisionsTenants;

/**
 * Runs when the platform provisions an organization for M&E (POST
 * /mel/platform/tenants), inside the tenant and a transaction. Safe to run
 * again: settings keep their values and existing dimensions are left alone.
 */
class SetUpWorkspace implements ProvisionsTenants
{
    /**
     * Starting dimensions, changed freely in Settings. Age groups are bands:
     * M&E never asks for dates of birth.
     */
    private const DIMENSIONS = [
        'Sex' => ['Female', 'Male'],
        'Age group' => ['Under 5', '5–17', '18–49', '50 and over'],
    ];

    public function provision(Organization $organization): void
    {
        $workspace = Workspace::current() ?? new Workspace;

        $workspace->fill([
            'display_name' => $organization->displayName,
            'currency' => $organization->defaultCurrency ?? 'USD',
            'timezone' => $organization->timezone ?? 'UTC',
        ])->save();

        if (Dimension::query()->exists()) {
            return;
        }

        foreach (self::DIMENSIONS as $name => $categories) {
            $dimension = Dimension::query()->create(['name' => $name]);
            foreach ($categories as $i => $category) {
                DimensionCategory::query()->create(['dimension_id' => $dimension->id, 'name' => $category, 'sort_order' => $i]);
            }
        }
    }
}
