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
 * A plan of income and spending per account and month of a financial
 * year, for the organization or one cost centre. Kept by App\Finance\Budgets.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $fiscal_year_id
 * @property string $name
 * @property string|null $cost_centre_id
 * @property string|null $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read FiscalYear $year
 * @property-read DimensionValue|null $costCentre
 * @property-read Collection<int, BudgetLine> $lines
 */
class Budget extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<FiscalYear, $this>
     */
    public function year(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    /**
     * @return BelongsTo<DimensionValue, $this>
     */
    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(DimensionValue::class, 'cost_centre_id');
    }

    /**
     * @return HasMany<BudgetLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class);
    }
}
