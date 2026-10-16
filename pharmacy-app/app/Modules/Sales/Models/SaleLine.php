<?php

namespace App\Modules\Sales\Models;

use App\Modules\Inventory\Models\Batch;
use App\Modules\Pharmacy\Models\Medicine;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Part of a sale taken from one batch. Tenant-safe only through its sale.
 *
 * @property string $id
 * @property string $sale_id
 * @property string $medicine_id
 * @property string $batch_id
 * @property int $quantity
 * @property int $unit_price_minor
 * @property int $line_total_minor
 * @property-read Medicine $medicine
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
            'line_total_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Medicine, $this>
     */
    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    /**
     * @return BelongsTo<Batch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
