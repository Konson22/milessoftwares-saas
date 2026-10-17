<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * How many units of a batch are kept at a location. Together they never
 * exceed the batch's quantity on hand; the rest is not put away. Only the
 * Locations service and the Stock service change them.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $batch_id
 * @property string $stock_location_id
 * @property int $quantity
 * @property-read Batch $batch
 * @property-read StockLocation $location
 */
class BatchLocation extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    /**
     * @return BelongsTo<Batch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * @return BelongsTo<StockLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id');
    }
}
