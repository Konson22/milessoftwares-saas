<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * This product's settings for one organization (one row per tenant).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $slug
 * @property string $display_name
 * @property string $currency
 * @property string $timezone
 */
class Workspace extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['slug', 'display_name', 'currency', 'timezone'];

    /**
     * The current tenant's row (null until the platform has provisioned it).
     */
    public static function current(): ?self
    {
        return static::query()->first();
    }
}
