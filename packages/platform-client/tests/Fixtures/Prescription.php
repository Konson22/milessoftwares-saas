<?php

namespace Miles\PlatformClient\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A tenant-owned product record.
 */
class Prescription extends Model
{
    use BelongsToTenant;
    use HasUuids;

    // Fully unguarded on purpose: BelongsToTenant alone must keep
    // organization_id out of mass assignment.
    protected $guarded = [];
}
