<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $invoice_id
 * @property string|null $shipment_id
 * @property string $description
 * @property int $amount_minor
 * @property-read Shipment|null $shipment
 */
class InvoiceLine extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }

    /**
     * @return BelongsTo<Shipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
