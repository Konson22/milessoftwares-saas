<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Stock of one medicine in one branch, from one batch. Only the Stock
 * service changes quantity_on_hand, together with a StockMovement.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $medicine_id
 * @property string $batch_number
 * @property CarbonImmutable $expires_on
 * @property int $unit_cost_minor
 * @property int $quantity_on_hand
 * @property-read Medicine $medicine
 */
class Batch extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id', 'quantity_on_hand'];

    protected function casts(): array
    {
        return [
            'expires_on' => 'immutable_date',
            'unit_cost_minor' => 'integer',
            'quantity_on_hand' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Medicine, $this>
     */
    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    /**
     * Expired on or before today: never sold.
     */
    public function isExpired(?CarbonImmutable $today = null): bool
    {
        return $this->expires_on->lessThanOrEqualTo(($today ?? CarbonImmutable::today())->startOfDay());
    }
}
