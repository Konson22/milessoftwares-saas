<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * What one "send to the kitchen" sent: the order's new lines at that
 * moment. The kitchen marks it ready, the waiter served.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $order_id
 * @property string $number
 * @property string $status
 * @property string $sent_by
 * @property CarbonImmutable $sent_at
 * @property CarbonImmutable|null $ready_at
 * @property CarbonImmutable|null $served_at
 * @property-read Order $order
 * @property-read User $sender
 */
class KitchenTicket extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const PREPARING = 'preparing';

    public const READY = 'ready';

    public const SERVED = 'served';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'ready_at' => 'datetime',
            'served_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<OrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('added_at')->orderBy('id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
