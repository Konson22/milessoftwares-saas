<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A vehicle and driver sent out with shipments (App\Actions\Trips): PLANNED,
 * IN_PROGRESS once dispatched, then COMPLETED; CANCELLED before it leaves.
 * Its margin is what its delivered shipments charge less its costs.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $number
 * @property string $vehicle_id
 * @property string $driver_id
 * @property string|null $route
 * @property CarbonImmutable $planned_on
 * @property string $status
 * @property int|null $start_odometer_km
 * @property int|null $end_odometer_km
 * @property string $created_by
 * @property CarbonImmutable|null $dispatched_at
 * @property CarbonImmutable|null $completed_at
 * @property string|null $cancel_reason
 * @property-read Vehicle $vehicle
 * @property-read Driver $driver
 */
class Trip extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const PLANNED = 'planned';

    public const IN_PROGRESS = 'in_progress';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::PLANNED, self::IN_PROGRESS, self::COMPLETED, self::CANCELLED];

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'planned_on' => 'date',
            'start_odometer_km' => 'integer',
            'end_odometer_km' => 'integer',
            'dispatched_at' => 'datetime',
            'completed_at' => 'datetime',
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
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * @return HasMany<Shipment, $this>
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class)->orderBy('number');
    }

    /**
     * @return HasMany<TripCost, $this>
     */
    public function costs(): HasMany
    {
        return $this->hasMany(TripCost::class)->orderBy('incurred_on')->orderBy('created_at');
    }

    public function distanceKm(): ?int
    {
        return $this->start_odometer_km === null || $this->end_odometer_km === null
            ? null
            : $this->end_odometer_km - $this->start_odometer_km;
    }
}
