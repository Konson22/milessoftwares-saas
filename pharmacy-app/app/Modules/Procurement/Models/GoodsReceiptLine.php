<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Item;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of a goods received note. Tenant-safe only through its receipt.
 * quantity and unit_cost_minor are per pack (BoughtInPacks); line_total_minor
 * is what the supplier billed for the line.
 *
 * @property string $id
 * @property string $goods_receipt_id
 * @property string $item_id
 * @property string $batch_id
 * @property string|null $purchase_order_line_id
 * @property string $batch_number
 * @property CarbonImmutable|null $expires_on null: an item that does not track expiry
 * @property int $quantity
 * @property int $unit_cost_minor
 * @property int $line_total_minor
 * @property-read Item $item
 */
class GoodsReceiptLine extends Model
{
    use BoughtInPacks;
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['goods_receipt_id'];

    protected function casts(): array
    {
        return [
            'expires_on' => 'immutable_date',
            'quantity' => 'integer',
            'unit_cost_minor' => 'integer',
            'line_total_minor' => 'integer',
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

    /**
     * @return BelongsTo<Batch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
