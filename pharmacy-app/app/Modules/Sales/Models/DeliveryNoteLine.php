<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * So many packs (or units) of an order line on a delivery note, and what
 * they were invoiced at. Tenant-safe only through its note.
 *
 * @property string $id
 * @property string $delivery_note_id
 * @property string $sales_order_line_id
 * @property int $quantity
 * @property int $amount_minor
 * @property-read SalesOrderLine $orderLine
 */
class DeliveryNoteLine extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['delivery_note_id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'amount_minor' => 'integer'];
    }

    /**
     * @return BelongsTo<SalesOrderLine, $this>
     */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_id');
    }
}
