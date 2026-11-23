<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A resource's fuel consumption rate from a moment on (ADR-0030 §4.6).
 * Append-only: a correction is a new row; entries keep the rate they were
 * calculated with.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $log_resource_id
 * @property 'diesel'|'petrol'|'other' $fuel_type
 * @property 'distance'|'hours' $method
 * @property string $rate decimal, litres per 100 km or per hour
 * @property CarbonImmutable $effective_from
 * @property string|null $note
 * @property string $created_by
 * @property CarbonImmutable|null $created_at
 * @property-read LogResource $resource
 * @property-read User $creator
 */
class FuelRate extends Model
{
    use TenantModel;

    public const UPDATED_AT = null;

    public const METHODS = ['distance', 'hours'];

    public const FUELS = ['diesel', 'petrol', 'other'];

    protected $table = 'log_resource_fuel_rates';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:3', 'effective_from' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<LogResource, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(LogResource::class, 'log_resource_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function unit(): string
    {
        return $this->method === 'distance' ? 'L/100 km' : 'L/h';
    }
}
