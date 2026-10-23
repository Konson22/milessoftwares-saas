<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * One batch on a count sheet. `expected` is what was on hand when the sheet
 * was made, then when the line was counted: the difference is counted less
 * that, so sales between the two never show as a shortage.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $stock_count_id
 * @property string $item_id
 * @property string $batch_id
 * @property int $expected
 * @property int|null $counted
 * @property string|null $counted_by
 * @property CarbonImmutable|null $counted_at
 * @property int|null $difference posted on completion
 * @property-read StockCount $sheet
 * @property-read Item $item
 * @property-read Batch $batch
 * @property-read User|null $counter
 */
class StockCountLine extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'expected' => 'integer',
            'counted' => 'integer',
            'difference' => 'integer',
            'counted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<StockCount, $this>
     */
    public function sheet(): BelongsTo
    {
        return $this->belongsTo(StockCount::class, 'stock_count_id');
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }
}
