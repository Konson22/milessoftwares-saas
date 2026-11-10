<?php

namespace App\Platform;

use App\Actions\Organizations;
use Miles\PlatformClient\Api\Organization;
use Miles\PlatformClient\Provisioning\ProvisionsTenants;

/**
 * Runs when the platform provisions an organization for Projects
 * (POST /projects/platform/tenants), in a transaction: the organization
 * here, with the platform's id. Safe to run again. Everything else a new
 * organization needs is made when first used.
 */
class SetUpWorkspace implements ProvisionsTenants
{
    public function provision(Organization $organization): void
    {
        Organizations::mirror($organization);
    }
}
