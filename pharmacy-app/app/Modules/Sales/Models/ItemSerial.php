<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * One unit of an item that tracks serial numbers: in stock, or sold (with
 * the sale it left in). App\Modules\Sales\Actions\Serials.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $item_id
 * @property string $serial
 * @property string $status in_stock|sold
 * @property string|null $sale_id
 * @property string $recorded_by
 * @property CarbonImmutable $recorded_at
 * @property CarbonImmutable|null $sold_at
 * @property-read Item $item
 * @property-read Sale|null $sale
 * @property-read User $recorder
 */
class ItemSerial extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const IN_STOCK = 'in_stock';

    public const SOLD = 'sold';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'immutable_datetime',
            'sold_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
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
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
