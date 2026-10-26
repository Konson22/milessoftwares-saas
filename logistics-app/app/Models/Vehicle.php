<?php

namespace App\Models;

use App\Enums\VehicleKind;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A vehicle of the fleet. AVAILABLE may be sent on trips; IN_MAINTENANCE and
 * RETIRED may not. Being out on a trip comes from the trips themselves.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $registration
 * @property VehicleKind $kind
 * @property string|null $description
 * @property int|null $capacity_kg
 * @property int $odometer_km
 * @property string $status
 */
class Vehicle extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const AVAILABLE = 'available';

    public const IN_MAINTENANCE = 'maintenance';

    public const RETIRED = 'retired';

    public const STATUSES = [self::AVAILABLE, self::IN_MAINTENANCE, self::RETIRED];

    protected $fillable = ['registration', 'kind', 'description', 'capacity_kg', 'odometer_km', 'status'];

    protected function casts(): array
    {
        return [
            'kind' => VehicleKind::class,
            'capacity_kg' => 'integer',
            'odometer_km' => 'integer',
        ];
    }

    /**
     * @return HasMany<Trip, $this>
     */
    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    /**
     * @return HasMany<MaintenanceRecord, $this>
     */
    public function maintenance(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class)->latest('performed_on');
    }

    public function label(): string
    {
        return $this->description === null ? $this->registration : "{$this->registration} · {$this->description}";
    }
}
