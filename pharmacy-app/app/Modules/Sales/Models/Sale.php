<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Sales\Enums\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A sale at a branch counter. Prices are what they were at the time.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $number
 * @property string $status
 * @property string|null $customer_name
 * @property string $currency
 * @property int $total_minor
 * @property int $discount_minor
 * @property string|null $submission_id
 * @property PaymentMethod $payment_method
 * @property string|null $payment_reference
 * @property int|null $amount_tendered_minor
 * @property int|null $change_minor
 * @property string $sold_by
 * @property CarbonImmutable $sold_at
 * @property string|null $voided_by
 * @property CarbonImmutable|null $voided_at
 * @property string|null $void_reason
 * @property string|null $prescription_id
 * @property string|null $till_session_id
 * @property string|null $void_till_session_id
 * @property-read User $seller
 * @property-read User|null $voider
 * @property-read Collection<int, SaleLine> $lines
 * @property-read Collection<int, SaleReturn> $returns
 */
class Sale extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const COMPLETED = 'completed';

    public const VOIDED = 'voided';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'payment_method' => PaymentMethod::class,
            'total_minor' => 'integer',
            'discount_minor' => 'integer',
            'amount_tendered_minor' => 'integer',
            'change_minor' => 'integer',
            'sold_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sold_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * @return HasMany<SaleLine, $this>
     */
    public function lines(): HasMany
    {
        // Ids are time-ordered (UUID v7): lines in the order they were sold.
        return $this->hasMany(SaleLine::class)->orderBy('id');
    }

    /**
     * @return HasMany<SaleReturn, $this>
     */
    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class)->orderBy('returned_at');
    }
}
