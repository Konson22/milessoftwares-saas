<?php

namespace App\Models;

use App\Enums\TripCostCategory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $trip_id
 * @property TripCostCategory $category
 * @property string|null $description
 * @property int $amount_minor
 * @property CarbonImmutable $incurred_on
 * @property string $recorded_by
 * @property-read User $recorder
 */
class TripCost extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'category' => TripCostCategory::class,
            'amount_minor' => 'integer',
            'incurred_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
