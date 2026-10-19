<?php

namespace App\Modules\Sales\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Prices a group of customers buys at (wholesale, a trade price), by the
 * unit or by the pack. Items it does not price sell at the shop's own
 * price (App\Modules\Sales\Actions\PriceLists).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $application
 * @property string $name
 * @property bool $active
 * @property CarbonImmutable $created_at
 * @property-read Collection<int, PriceListPrice> $prices
 */
class PriceList extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected $attributes = ['active' => true];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Prices are reached through their list only (they carry no tenant of
     * their own).
     *
     * @return HasMany<PriceListPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(PriceListPrice::class);
    }
}
