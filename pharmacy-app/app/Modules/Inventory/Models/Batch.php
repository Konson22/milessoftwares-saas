<?php

namespace App\Modules\Inventory\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Stock of one item in one branch, from one batch. Only the Stock
 * service changes quantity_on_hand, together with a StockMovement. A batch
 * on hold (quarantined or recalled) is kept but never sold.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $item_id
 * @property string $batch_number
 * @property CarbonImmutable $expires_on
 * @property int $unit_cost_minor
 * @property int $quantity_on_hand
 * @property string $status
 * @property string|null $hold_reason
 * @property string|null $held_by
 * @property CarbonImmutable|null $held_at
 * @property-read Item $item
 */
class Batch extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const AVAILABLE = 'available';

    /** Held while in doubt; released when cleared. */
    public const QUARANTINED = 'quarantined';

    /** Recalled by the maker or the regulator: never sold again. */
    public const RECALLED = 'recalled';

    protected $guarded = ['organization_id', 'quantity_on_hand', 'status'];

    protected function casts(): array
    {
        return [
            'expires_on' => 'immutable_date',
            'unit_cost_minor' => 'integer',
            'quantity_on_hand' => 'integer',
            'held_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function onHold(): bool
    {
        return $this->status !== self::AVAILABLE;
    }

    /**
     * Expired on or before today: never sold.
     */
    public function isExpired(?CarbonImmutable $today = null): bool
    {
        return $this->expires_on->lessThanOrEqualTo(($today ?? CarbonImmutable::today())->startOfDay());
    }
}
