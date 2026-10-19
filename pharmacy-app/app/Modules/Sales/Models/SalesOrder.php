<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Sales\Enums\SalesOrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A wholesale quotation, and the sales order it becomes when confirmed
 * (App\Modules\Sales\Actions\SalesOrders). Prices are fixed when quoted.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $application
 * @property string $branch_id the branch that sells and delivers it
 * @property string $customer_id
 * @property string|null $price_list_id
 * @property string $quote_number
 * @property string|null $order_number
 * @property SalesOrderStatus $status
 * @property CarbonImmutable $valid_until
 * @property string|null $customer_reference
 * @property string|null $notes
 * @property int $total_minor
 * @property string $created_by
 * @property string|null $confirmed_by
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable $created_at
 * @property-read Customer $customer
 * @property-read PriceList|null $priceList
 * @property-read User $creator
 * @property-read Collection<int, SalesOrderLine> $lines
 * @property-read Collection<int, DeliveryNote> $deliveries
 */
class SalesOrder extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id', 'status'];

    protected function casts(): array
    {
        return [
            'status' => SalesOrderStatus::class,
            'valid_until' => 'immutable_date',
            'total_minor' => 'integer',
            'confirmed_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * The order number once confirmed, the quotation's before.
     */
    public function reference(): string
    {
        return $this->order_number ?? $this->quote_number;
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<PriceList, $this>
     */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Lines are reached through their order only.
     *
     * @return HasMany<SalesOrderLine, $this>
     */
    public function lines(): HasMany
    {
        // Ids are time-ordered (UUID v7): lines in the order they were entered.
        return $this->hasMany(SalesOrderLine::class)->orderBy('id');
    }

    /**
     * @return HasMany<DeliveryNote, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(DeliveryNote::class)->orderBy('delivered_at');
    }
}
