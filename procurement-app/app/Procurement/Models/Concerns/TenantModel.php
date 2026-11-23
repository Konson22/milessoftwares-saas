<?php

namespace App\Procurement\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * What every Procurement record is: owned by one organization (scoped,
 * fail closed) and keyed by a UUIDv7.
 */
trait TenantModel
{
    use BelongsToTenant;
    use HasUuids;
}
