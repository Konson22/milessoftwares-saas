<?php

namespace App\Mel\Models\Concerns;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * What every M&E record is: owned by one organization (scoped, fail
 * closed, through Projects' own tenancy) and keyed by a UUIDv7.
 */
trait TenantModel
{
    use BelongsToOrganization;
    use HasUuids;
}
