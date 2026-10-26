<?php

namespace App\Platform;

use App\Models\Workspace;
use Miles\PlatformClient\Api\Organization;
use Miles\PlatformClient\Provisioning\ProvisionsTenants;

/**
 * Runs when the platform provisions an organization for this product
 * (POST /platform/tenants), inside the tenant and a transaction. Create
 * whatever a new workspace needs here; it must be safe to run again.
 */
class SetUpWorkspace implements ProvisionsTenants
{
    public function provision(Organization $organization): void
    {
        $workspace = Workspace::current() ?? new Workspace;

        $workspace->fill([
            'slug' => $organization->slug,
            'display_name' => $organization->displayName,
            'currency' => $organization->defaultCurrency ?? 'USD',
            'timezone' => $organization->timezone ?? 'UTC',
        ])->save();
    }
}
