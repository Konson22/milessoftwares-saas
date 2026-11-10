<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Purchase cost of a grade, company-wide or for one station, until the next cost.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $branch_id
 * @property string $fuel_product_id
 * @property int $cost_minor
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property string $created_by
 */
class FuelCost extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'cost_minor' => 'integer',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }

    public static function current(string $productId, ?string $branchId): ?self
    {
        $open = fn ($query) => $query
            ->where('fuel_product_id', $productId)
            ->where('effective_from', '<=', now())
            ->where(fn ($prices) => $prices->whereNull('effective_to')->orWhere('effective_to', '>', now()))
            ->orderByDesc('effective_from');

        if ($branchId !== null) {
            $station = $open(static::query()->where('branch_id', $branchId))->first();
            if ($station !== null) {
                return $station;
            }
        }

        return $open(static::query()->whereNull('branch_id'))->first();
    }

    /**
     * @return BelongsTo<FuelProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(FuelProduct::class, 'fuel_product_id');
    }
}
