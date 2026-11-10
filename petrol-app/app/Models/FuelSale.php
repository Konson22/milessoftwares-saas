<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * One fuel sale on an open shift. The price and cost are copied onto the
 * row so a later price change does not rewrite history.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $shift_id
 * @property string $nozzle_id
 * @property string $attendant_id
 * @property string $fuel_product_id
 * @property string $tank_id
 * @property string $number
 * @property int $quantity_milli
 * @property int $unit_price_minor
 * @property int $total_minor
 * @property int $cost_minor
 * @property string $collected_by
 * @property string $status
 * @property string|null $idempotency_key
 * @property string|null $customer_id
 * @property string $sold_by
 * @property CarbonImmutable $sold_at
 * @property string|null $voided_by
 * @property CarbonImmutable|null $voided_at
 * @property string|null $void_reason
 * @property-read Nozzle $nozzle
 * @property-read Attendant $attendant
 * @property-read FuelProduct $product
 */
class FuelSale extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const POSTED = 'posted';

    public const VOIDED = 'voided';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'quantity_milli' => 'integer',
            'unit_price_minor' => 'integer',
            'total_minor' => 'integer',
            'cost_minor' => 'integer',
            'sold_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function isPosted(): bool
    {
        return $this->status === self::POSTED;
    }

    /**
     * @return HasMany<SalePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    /**
     * @return BelongsTo<Nozzle, $this>
     */
    public function nozzle(): BelongsTo
    {
        return $this->belongsTo(Nozzle::class);
    }

    /**
     * @return BelongsTo<Attendant, $this>
     */
    public function attendant(): BelongsTo
    {
        return $this->belongsTo(Attendant::class);
    }

    /**
     * @return BelongsTo<FuelProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(FuelProduct::class, 'fuel_product_id');
    }

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
