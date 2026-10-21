<?php

namespace App\Modules\Sales\Models;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPack;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $counter_order_id
 * @property string $item_id
 * @property string|null $item_pack_id
 * @property int $quantity in packs when a pack is named, otherwise in units
 * @property int $discount_percent
 * @property int $line_total_minor
 * @property list<array{batch_id: string, quantity: int}>|null $taken the batches dispensed from, before payment
 * @property-read Item $item
 * @property-read ItemPack|null $pack
 */
class CounterOrderLine extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'discount_percent' => 'integer',
            'line_total_minor' => 'integer',
            'taken' => 'array',
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
     * @return BelongsTo<ItemPack, $this>
     */
    public function pack(): BelongsTo
    {
        return $this->belongsTo(ItemPack::class, 'item_pack_id');
    }
}
