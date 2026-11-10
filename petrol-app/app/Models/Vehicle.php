<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * @property string $id
 * @property string $customer_id
 * @property string $registration
 * @property string|null $details
 */
class Vehicle extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];
}
