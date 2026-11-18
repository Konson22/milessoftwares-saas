<?php

namespace App\Collect\Models;

use App\Mel\Models\Concerns\TenantModel;
use App\Mel\Models\Indicator;
use App\Mel\Models\ProgrammeUnit;
use App\Mel\Models\ReportingPeriod;
use App\Mel\Models\Submission as MelSubmission;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One calculation of a mapping for a reporting period and unit, sent to
 * M&E as a draft result (ADR-0019 §26). Its rows are the submissions it
 * counted. The next run for the same period and unit replaces it; it is
 * never edited.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $mapping_id
 * @property string $indicator_id
 * @property string $reporting_period_id
 * @property string $programme_unit_id
 * @property string|null $value
 * @property string|null $numerator
 * @property string|null $denominator
 * @property int $considered
 * @property int $accepted
 * @property int $used
 * @property array<string, int> $excluded
 * @property string $status
 * @property string|null $mel_submission_id
 * @property CarbonImmutable|null $flagged_at
 * @property string|null $flag_reason
 * @property string $created_by
 * @property CarbonImmutable $created_at
 * @property-read IndicatorMapping $mapping
 * @property-read Indicator $indicator
 * @property-read ReportingPeriod $period
 * @property-read ProgrammeUnit $unit
 * @property-read MelSubmission|null $melSubmission
 * @property-read User $author
 * @property-read Collection<int, CalculationRow> $rows
 */
class CalculationRun extends Model
{
    use TenantModel;

    protected $table = 'collect_calculation_runs';

    public const SENT = 'sent';

    public const SUPERSEDED = 'superseded';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'considered' => 'integer',
            'accepted' => 'integer',
            'used' => 'integer',
            'excluded' => 'array',
            'flagged_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<IndicatorMapping, $this>
     */
    public function mapping(): BelongsTo
    {
        return $this->belongsTo(IndicatorMapping::class, 'mapping_id');
    }

    /**
     * @return BelongsTo<Indicator, $this>
     */
    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }

    /**
     * @return BelongsTo<ReportingPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(ReportingPeriod::class, 'reporting_period_id');
    }

    /**
     * @return BelongsTo<ProgrammeUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProgrammeUnit::class, 'programme_unit_id');
    }

    /**
     * @return BelongsTo<MelSubmission, $this>
     */
    public function melSubmission(): BelongsTo
    {
        return $this->belongsTo(MelSubmission::class, 'mel_submission_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<CalculationRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(CalculationRow::class, 'run_id');
    }
}
