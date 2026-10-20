<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * An asset in the register. Kept by App\Finance\FixedAssets.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $number
 * @property string $name
 * @property string $asset_category_id
 * @property CarbonImmutable $acquired_on
 * @property int $cost_minor
 * @property int $residual_minor
 * @property int $opening_depreciation_minor
 * @property string $method
 * @property int $life_months
 * @property int|null $rate_bp
 * @property string|null $acquisition_journal_id
 * @property string|null $bill_id
 * @property CarbonImmutable|null $disposed_on
 * @property int|null $disposal_proceeds_minor
 * @property string|null $disposal_journal_id
 * @property string|null $created_by
 * @property-read AssetCategory $category
 * @property-read Collection<int, DepreciationLine> $depreciation
 * @property-read Journal|null $acquisitionJournal
 * @property-read Bill|null $bill
 */
class FixedAsset extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'acquired_on' => 'immutable_date',
            'disposed_on' => 'immutable_date',
            'cost_minor' => 'integer',
            'residual_minor' => 'integer',
            'opening_depreciation_minor' => 'integer',
            'life_months' => 'integer',
            'rate_bp' => 'integer',
            'disposal_proceeds_minor' => 'integer',
        ];
    }

    /**
     * Depreciation so far: brought in, plus every run.
     */
    public function accumulated(): int
    {
        return $this->opening_depreciation_minor + (int) $this->depreciation()->sum('amount_minor');
    }

    public function disposed(): bool
    {
        return $this->disposed_on !== null;
    }

    /**
     * The first month depreciated: the month it was acquired.
     */
    public function firstMonth(): CarbonImmutable
    {
        return $this->acquired_on->startOfMonth();
    }

    /**
     * @return BelongsTo<AssetCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    /**
     * @return HasMany<DepreciationLine, $this>
     */
    public function depreciation(): HasMany
    {
        return $this->hasMany(DepreciationLine::class);
    }

    /**
     * @return BelongsTo<Journal, $this>
     */
    public function acquisitionJournal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'acquisition_journal_id');
    }

    /**
     * @return BelongsTo<Bill, $this>
     */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }
}
