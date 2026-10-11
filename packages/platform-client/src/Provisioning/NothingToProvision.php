<?php

namespace Miles\PlatformClient\Provisioning;

use Miles\PlatformClient\Api\Organization;

/**
 * Default when a product needs no setup per organization.
 */
final class NothingToProvision implements ProvisionsTenants
{
    public function provision(Organization $organization): void {}
}
