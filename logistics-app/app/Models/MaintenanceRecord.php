<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Servicing or a repair of a vehicle, and what it cost.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $vehicle_id
 * @property CarbonImmutable $performed_on
 * @property string $description
 * @property string|null $garage
 * @property int|null $odometer_km
 * @property int $cost_minor
 * @property string $recorded_by
 * @property-read User $recorder
 */
class MaintenanceRecord extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'performed_on' => 'date',
            'odometer_km' => 'integer',
            'cost_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
