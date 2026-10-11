<?php

namespace Miles\PlatformClient\Provisioning;

use Miles\PlatformClient\Api\Organization;

/**
 * Bind this in the product to prepare a newly entitled organization
 * (default settings, a first branch, ...). It runs inside the tenant and a
 * database transaction, once per platform provisioning step; throwing makes
 * the platform retry later.
 */
interface ProvisionsTenants
{
    public function provision(Organization $organization): void;
}
