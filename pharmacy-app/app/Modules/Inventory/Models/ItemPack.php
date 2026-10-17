<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A way an item is bought and sold: a box of 30 tablets. Stock is still
 * counted in the item's unit; documents copy the pack's name and size (and
 * sales its price) when they use it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $item_id
 * @property string $name
 * @property int $units
 * @property int|null $sell_price_minor null: its units at the item's unit price
 */
class ItemPack extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'units' => 'integer',
            'sell_price_minor' => 'integer',
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
     * What one pack sells for today.
     */
    public function sellPrice(Item $item): int
    {
        return $this->sell_price_minor ?? $this->units * $item->sell_price_minor;
    }

    /**
     * "box of 30"
     */
    public function label(): string
    {
        return "{$this->name} of {$this->units}";
    }
}
