<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A paid bill of an order, into the cashier's till: Finance hears of it as
 * an invoice paid at once. Voided whole, once (App\Actions\Bills). An order
 * paid in parts has several: some of its lines each, or equal shares of what
 * was left (part of parts). The subtotal less the discount is what was taken.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $order_id
 * @property string $till_shift_id
 * @property string $number
 * @property string $currency
 * @property int $subtotal_minor
 * @property int $discount_minor
 * @property string|null $discount_reason
 * @property string|null $discounted_by
 * @property int|null $part
 * @property int|null $parts
 * @property int $total_minor
 * @property int $cost_minor
 * @property PaymentMethod $payment_method
 * @property int|null $tendered_minor
 * @property int $change_minor
 * @property string|null $payment_reference
 * @property string|null $customer_name
 * @property string $status
 * @property string $paid_by
 * @property CarbonImmutable $paid_at
 * @property string|null $voided_by
 * @property CarbonImmutable|null $voided_at
 * @property string|null $void_reason
 * @property string|null $refund_till_shift_id
 * @property-read Order $order
 * @property-read TillShift $till
 * @property-read User $cashier
 * @property-read User|null $voider
 */
class Bill extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const PAID = 'paid';

    public const VOIDED = 'voided';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'part' => 'integer',
            'parts' => 'integer',
            'total_minor' => 'integer',
            'cost_minor' => 'integer',
            'tendered_minor' => 'integer',
            'change_minor' => 'integer',
            'payment_method' => PaymentMethod::class,
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<BillLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BillLine::class)->orderBy('name');
    }

    /** An equal share of what was left of the order, not some of its lines. */
    public function isShare(): bool
    {
        return $this->parts !== null;
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<TillShift, $this>
     */
    public function till(): BelongsTo
    {
        return $this->belongsTo(TillShift::class, 'till_shift_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
