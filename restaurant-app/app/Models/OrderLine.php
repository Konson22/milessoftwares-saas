<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A dish or drink on an order, with a note for the kitchen. New until it is
 * sent on a kitchen ticket; a voided line stays on the order (and its
 * ticket) but is never billed. Tenant-safe only through its order.
 *
 * @property string $id
 * @property string $order_id
 * @property string $menu_item_id
 * @property int $quantity
 * @property string|null $note
 * @property string|null $kitchen_ticket_id
 * @property string $added_by
 * @property CarbonImmutable $added_at
 * @property string|null $voided_by
 * @property CarbonImmutable|null $voided_at
 * @property string|null $void_reason
 * @property-read MenuItem $item
 * @property-read KitchenTicket|null $ticket
 * @property-read User|null $voider
 */
class OrderLine extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['order_id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'added_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function sent(): bool
    {
        return $this->kitchen_ticket_id !== null;
    }

    public function voided(): bool
    {
        return $this->voided_at !== null;
    }

    /**
     * @return BelongsTo<MenuItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }

    /**
     * @return BelongsTo<KitchenTicket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(KitchenTicket::class, 'kitchen_ticket_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
