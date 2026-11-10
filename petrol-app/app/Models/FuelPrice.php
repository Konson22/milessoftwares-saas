<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * The selling price of a grade at one station, from a moment until the next price.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $fuel_product_id
 * @property int $selling_price_minor
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property string $created_by
 */
class FuelPrice extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'selling_price_minor' => 'integer',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }

    public static function current(string $branchId, string $productId): ?self
    {
        return static::query()
            ->where('branch_id', $branchId)
            ->where('fuel_product_id', $productId)
            ->where('effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()))
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * @return BelongsTo<FuelProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(FuelProduct::class, 'fuel_product_id');
    }
}
