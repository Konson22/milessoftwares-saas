<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $name
 * @property string $code
 * @property string $status
 */
class Pump extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const ACTIVE = 'active';

    public const MAINTENANCE = 'maintenance';

    public const OUT = 'out_of_service';

    protected $guarded = ['organization_id'];

    /**
     * @return HasMany<Nozzle, $this>
     */
    public function nozzles(): HasMany
    {
        return $this->hasMany(Nozzle::class);
    }
}
