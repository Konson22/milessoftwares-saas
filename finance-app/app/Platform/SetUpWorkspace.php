<?php

namespace App\Platform;

use App\Finance\ChartOfAccounts;
use App\Models\Workspace;
use Miles\PlatformClient\Api\Organization;
use Miles\PlatformClient\Provisioning\ProvisionsTenants;

/**
 * Runs when the platform provisions an organization for Finance
 * (POST /platform/tenants), inside the tenant and a transaction: the
 * workspace settings and the starting chart of accounts. Safe to run again.
 */
class SetUpWorkspace implements ProvisionsTenants
{
    public function __construct(private readonly ChartOfAccounts $chart) {}

    public function provision(Organization $organization): void
    {
        $workspace = Workspace::current() ?? new Workspace;

        // The books' currency is fixed once set: amounts never change units.
        $workspace->fill([
            'slug' => $organization->slug,
            'display_name' => $organization->displayName,
            'currency' => $workspace->exists ? $workspace->currency : ($organization->defaultCurrency ?? 'USD'),
            'timezone' => $organization->timezone ?? 'UTC',
        ])->save();

        $this->chart->ensure();
    }
}
