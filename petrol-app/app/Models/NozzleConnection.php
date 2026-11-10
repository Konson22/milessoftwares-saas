<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Which tank a nozzle draws from. One current row per nozzle.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $nozzle_id
 * @property string $tank_id
 * @property CarbonImmutable $connected_at
 * @property CarbonImmutable|null $disconnected_at
 * @property-read Tank $tank
 */
class NozzleConnection extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'nozzle_tank_connections';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tank, $this>
     */
    public function tank(): BelongsTo
    {
        return $this->belongsTo(Tank::class);
    }
}
