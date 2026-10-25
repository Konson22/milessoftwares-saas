<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A drink on hand at a branch, at its average cost. Changed only by
 * App\Actions\Stock, which records each change as a StockMovement.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $menu_item_id
 * @property int $quantity
 * @property int $average_cost_minor
 * @property-read MenuItem $item
 */
class StockLevel extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'average_cost_minor' => 'integer'];
    }

    /**
     * @return BelongsTo<MenuItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }
}
