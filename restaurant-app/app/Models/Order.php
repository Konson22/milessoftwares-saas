<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * An order served at a table, or taken away: its lines go to the kitchen
 * as tickets, and it is closed by paying its bill or cancelled. Only an
 * open order changes (App\Actions\Orders).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $number
 * @property string $service dine_in, takeaway
 * @property string|null $dining_table_id
 * @property int|null $guests
 * @property string|null $customer_name
 * @property string $status
 * @property string $opened_by
 * @property CarbonImmutable $opened_at
 * @property string|null $closed_by
 * @property CarbonImmutable|null $closed_at
 * @property string|null $cancel_reason
 * @property-read DiningTable|null $table
 * @property-read Bill|null $bill
 * @property-read User $opener
 */
class Order extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const OPEN = 'open';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    public const DINE_IN = 'dine_in';

    public const TAKEAWAY = 'takeaway';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'guests' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    /**
     * @return HasMany<OrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('added_at')->orderBy('id');
    }

    /**
     * @return HasMany<KitchenTicket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(KitchenTicket::class)->orderBy('sent_at');
    }

    /**
     * @return BelongsTo<DiningTable, $this>
     */
    public function table(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }

    /**
     * @return HasOne<Bill, $this>
     */
    public function bill(): HasOne
    {
        return $this->hasOne(Bill::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }
}
