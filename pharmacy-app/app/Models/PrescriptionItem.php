<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A medicine on a prescription. Tenant-safe only through its prescription.
 *
 * @property string $id
 * @property string $prescription_id
 * @property string $medicine_id
 * @property int $quantity
 * @property string $directions
 * @property-read Medicine $medicine
 */
class PrescriptionItem extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['prescription_id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    /**
     * @return BelongsTo<Medicine, $this>
     */
    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }
}
