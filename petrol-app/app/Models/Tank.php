<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A storage tank. quantity_milli is the cache of stock movements;
 * both are updated in the same locked transaction.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $fuel_product_id
 * @property string $name
 * @property string $code
 * @property string $tank_type
 * @property string|null $material
 * @property int $capacity_milli
 * @property int|null $safe_fill_milli
 * @property int $quantity_milli
 * @property int $average_cost_minor
 * @property int|null $low_stock_milli
 * @property int|null $reorder_milli
 * @property string $status
 * @property string|null $calibration_notes
 * @property string|null $inspected_on
 * @property string|null $inspection_notes
 * @property string|null $next_inspection_on
 * @property-read FuelProduct $product
 */
class Tank extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const ACTIVE = 'active';

    public const OUT = 'out_of_service';

    public const MAINTENANCE = 'maintenance';

    public const UNDERGROUND = 'underground';

    public const ABOVEGROUND = 'aboveground';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'capacity_milli' => 'integer',
            'quantity_milli' => 'integer',
            'average_cost_minor' => 'integer',
            'low_stock_milli' => 'integer',
            'safe_fill_milli' => 'integer',
            'reorder_milli' => 'integer',
            'inspected_on' => 'date',
            'next_inspection_on' => 'date',
            'water_alert' => 'boolean',
        ];
    }

    public function isLow(int $percent): bool
    {
        if ($this->low_stock_milli !== null && $this->quantity_milli <= $this->low_stock_milli) {
            return true;
        }

        if ($this->capacity_milli <= 0) {
            return false;
        }

        return $this->quantity_milli * 100 <= $this->capacity_milli * $percent;
    }

    public function needsReorder(): bool
    {
        return $this->reorder_milli !== null && $this->quantity_milli <= $this->reorder_milli;
    }

    /**
     * Litres the tank may hold: the safe fill when one is set, otherwise capacity.
     */
    public function fillLimit(): int
    {
        if ($this->safe_fill_milli !== null && $this->safe_fill_milli > 0) {
            return min($this->capacity_milli, $this->safe_fill_milli);
        }

        return $this->capacity_milli;
    }

    /**
     * @return BelongsTo<FuelProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(FuelProduct::class, 'fuel_product_id');
    }
}
