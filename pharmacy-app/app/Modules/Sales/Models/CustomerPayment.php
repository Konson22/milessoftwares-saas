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
 * Money a customer paid against what they owe, shared over their oldest
 * sales on account (allocations).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $application
 * @property string $branch_id
 * @property string $customer_id
 * @property string $number
 * @property PaymentMethod $method
 * @property string|null $reference
 * @property int $amount_minor
 * @property string|null $note
 * @property string|null $till_session_id
 * @property string $received_by
 * @property CarbonImmutable $received_at
 * @property string|null $submission_id
 * @property-read Customer $customer
 * @property-read User $receiver
 * @property-read Collection<int, CustomerPaymentAllocation> $allocations
 */
class CustomerPayment extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'amount_minor' => 'integer',
            'received_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * @return HasMany<CustomerPaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(CustomerPaymentAllocation::class);
    }
}
