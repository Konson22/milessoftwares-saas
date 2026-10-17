<?php

namespace App\Modules\Sales\Models;

use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Part of a sale taken from one batch. Tenant-safe only through its sale.
 *
 * @property string $id
 * @property string $sale_id
 * @property string $item_id
 * @property string $batch_id
 * @property int $quantity
 * @property int $unit_price_minor
 * @property int $discount_minor
 * @property int $line_total_minor
 * @property string|null $pack_name sold by the pack: its name, size and price as sold
 * @property int $units_per_pack
 * @property int|null $pack_price_minor
 * @property-read Item $item
 * @property-read Batch $batch
 */
class SaleLine extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['sale_id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_minor' => 'integer',
            'discount_minor' => 'integer',
            'line_total_minor' => 'integer',
            'units_per_pack' => 'integer',
            'pack_price_minor' => 'integer',
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
