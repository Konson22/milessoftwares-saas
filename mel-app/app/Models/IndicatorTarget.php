<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What an indicator should reach in one reporting period, for the programme
 * or one unit; a range for "maintain" indicators. Never edited: a change
 * supersedes this row (App\Mel\Targets).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $indicator_id
 * @property string|null $unit_id
 * @property string $reporting_period_id
 * @property string|null $value
 * @property string|null $numerator
 * @property string|null $denominator
 * @property string|null $min_value
 * @property string|null $max_value
 * @property string|null $notes
 * @property string|null $supersedes_id
 * @property CarbonImmutable|null $superseded_at
 * @property string|null $change_reason
 * @property string $created_by
 * @property CarbonImmutable $created_at
 * @property-read Indicator $indicator
 * @property-read ProgrammeUnit|null $unit
 * @property-read ReportingPeriod $period
 * @property-read User $author
 */
class IndicatorTarget extends Model
{
    use TenantModel;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:6',
            'numerator' => 'decimal:6',
            'denominator' => 'decimal:6',
            'min_value' => 'decimal:6',
            'max_value' => 'decimal:6',
            'superseded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Indicator, $this>
     */
    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }

    /**
     * @return BelongsTo<ProgrammeUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProgrammeUnit::class, 'unit_id');
    }

    /**
     * @return BelongsTo<ReportingPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(ReportingPeriod::class, 'reporting_period_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
