<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A way an item is bought: a box of 30 tablets. Stock is still counted in
 * the item's unit; documents copy the pack's name and size when they use it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $item_id
 * @property string $name
 * @property int $units
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
     * "box of 30"
     */
    public function label(): string
    {
        return "{$this->name} of {$this->units}";
    }
}
