<?php

namespace App\Support\Tenancy;

use LogicException;

/**
 * Thrown when tenant-owned data is queried without an organization context.
 * Failing closed is deliberate: an unscoped query is a potential cross-tenant
 * leak, so it must be made explicit with acrossTenants().
 */
final class MissingTenantContext extends LogicException
{
    public function __construct(string $message = 'Tenant-owned data was accessed without a tenant context.')
    {
        parent::__construct($message);
    }
}
