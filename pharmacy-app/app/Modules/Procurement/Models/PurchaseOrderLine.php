<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of a purchase order. Tenant-safe only through its order.
 * Quantities and unit_cost_minor are per pack (BoughtInPacks).
 *
 * @property string $id
 * @property string $purchase_order_id
 * @property string $item_id
 * @property int $quantity_ordered
 * @property int $quantity_received
 * @property int $unit_cost_minor
 * @property-read Item $item
 */
class PurchaseOrderLine extends Model
{
    use BoughtInPacks;
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['purchase_order_id', 'quantity_received'];

    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'integer',
            'quantity_received' => 'integer',
            'unit_cost_minor' => 'integer',
            'units_per_pack' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function remaining(): int
    {
        return max(0, $this->quantity_ordered - $this->quantity_received);
    }
}
