<?php

namespace App\Modules\Sales\Models;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPack;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One price of a price list: an item's unit, or one of its packs.
 * Tenant-safe only through its list.
 *
 * @property string $id
 * @property string $price_list_id
 * @property string $item_id
 * @property string|null $item_pack_id null: the price of one unit
 * @property int $price_minor
 * @property-read Item $item
 * @property-read ItemPack|null $pack
 */
class PriceListPrice extends Model
{
    use HasUuids;

    protected $guarded = ['price_list_id'];

    protected function casts(): array
    {
        return ['price_minor' => 'integer'];
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
