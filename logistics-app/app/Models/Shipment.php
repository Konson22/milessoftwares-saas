<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Goods carried for a customer from pickup to drop-off (App\Actions\Shipments):
 * BOOKED, ASSIGNED to a planned trip, IN_TRANSIT once the trip leaves, then
 * DELIVERED (with proof) or FAILED (back to BOOKED when the trip ends).
 * CANCELLED before it goes anywhere. Delivered shipments are invoiced.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $number
 * @property string $customer_id
 * @property string $status
 * @property string $pickup_address
 * @property string $delivery_address
 * @property string $recipient_name
 * @property string|null $recipient_phone
 * @property string $goods
 * @property int $packages
 * @property string|null $weight_kg
 * @property int $charge_minor
 * @property CarbonImmutable|null $pickup_on
 * @property string|null $trip_id
 * @property string|null $invoice_id
 * @property string $booked_by
 * @property CarbonImmutable|null $delivered_at
 * @property string|null $received_by
 * @property string|null $delivery_note
 * @property string|null $cancel_reason
 * @property CarbonImmutable $created_at
 * @property-read Customer $customer
 * @property-read Trip|null $trip
 * @property-read Invoice|null $invoice
 * @property-read User $booker
 */
class Shipment extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const BOOKED = 'booked';

    public const ASSIGNED = 'assigned';

    public const IN_TRANSIT = 'in_transit';

    public const DELIVERED = 'delivered';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::BOOKED, self::ASSIGNED, self::IN_TRANSIT, self::DELIVERED, self::FAILED, self::CANCELLED];

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'packages' => 'integer',
            'charge_minor' => 'integer',
            'pickup_on' => 'date',
            'delivered_at' => 'datetime',
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
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function booker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booked_by');
    }

    /**
     * @return HasMany<ShipmentEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class)->orderBy('occurred_at')->orderBy('created_at');
    }

    /**
     * Not yet on its way: its details may still change.
     */
    public function editable(): bool
    {
        return in_array($this->status, [self::BOOKED, self::ASSIGNED], true);
    }

    /**
     * Number, recipient, goods or addresses containing the text.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSearch(Builder $query, string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }

        $like = '%'.mb_strtolower($text).'%';
        $query->where(fn (Builder $query) => $query
            ->whereRaw('lower(number) like ?', [$like])
            ->orWhereRaw('lower(recipient_name) like ?', [$like])
            ->orWhereRaw('lower(goods) like ?', [$like])
            ->orWhereRaw('lower(pickup_address) like ?', [$like])
            ->orWhereRaw('lower(delivery_address) like ?', [$like]));
    }
}
