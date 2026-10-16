<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Inventory\Models\Batch;
use App\Modules\Pharmacy\Models\Medicine;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A quantity of one received batch going back. Tenant-safe only through
 * its return.
 *
 * @property string $id
 * @property string $supplier_return_id
 * @property string $goods_receipt_line_id
 * @property string $medicine_id
 * @property string $batch_id
 * @property int $quantity
 * @property int $unit_cost_minor
 * @property int $line_total_minor
 * @property-read Medicine $medicine
 * @property-read Batch $batch
 */
class SupplierReturnLine extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['supplier_return_id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost_minor' => 'integer',
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
