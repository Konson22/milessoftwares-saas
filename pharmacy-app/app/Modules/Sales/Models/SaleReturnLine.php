<?php

namespace App\Modules\Sales\Models;

use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Units of one sale line brought back. Tenant-safe only through its return.
 *
 * @property string $id
 * @property string $sale_return_id
 * @property string $sale_line_id
 * @property string $item_id
 * @property string $batch_id
 * @property int $quantity
 * @property int $amount_minor
 * @property int $cost_minor
 * @property bool $restocked
 * @property-read Item $item
 * @property-read Batch $batch
 */
class SaleReturnLine extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['sale_return_id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'amount_minor' => 'integer',
            'cost_minor' => 'integer',
            'restocked' => 'boolean',
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
