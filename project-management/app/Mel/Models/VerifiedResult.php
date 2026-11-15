<?php

namespace App\Mel\Models;

use App\Mel\Models\Concerns\TenantModel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The frozen copy of a verified value; what every report reads. Only its
 * publication and its replacement by a verified correction are ever set.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $submission_id
 * @property string $programme_id
 * @property string $indicator_id
 * @property string $indicator_version_id
 * @property string $reporting_period_id
 * @property string $unit_id
 * @property string|null $value
 * @property string|null $numerator
 * @property string|null $denominator
 * @property list<array{breakdown_id: string, category_id: string, second_category_id: string|null, value: string|null, numerator: string|null, denominator: string|null}>|null $cells
 * @property string $verified_by
 * @property CarbonImmutable $verified_at
 * @property string|null $published_by
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $superseded_at
 * @property CarbonImmutable $created_at
 * @property-read Submission $submission
 * @property-read Indicator $indicator
 * @property-read IndicatorVersion $version
 * @property-read ReportingPeriod $period
 * @property-read ProgrammeUnit $unit
 * @property-read User $verifier
 * @property-read User|null $publisher
 */
class VerifiedResult extends Model
{
    protected $table = 'mel_verified_results';

    use TenantModel;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:6',
            'numerator' => 'decimal:6',
            'denominator' => 'decimal:6',
            'cells' => 'array',
            'verified_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * The results standing now (not replaced by a correction).
     *
     * @param  Builder<VerifiedResult>  $query
     * @return Builder<VerifiedResult>
     */
    public function scopeStanding(Builder $query): Builder
    {
        return $query->whereNull('superseded_at');
    }

    /**
     * @return BelongsTo<Submission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /**
     * @return BelongsTo<Indicator, $this>
     */
    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }

    /**
     * @return BelongsTo<IndicatorVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(IndicatorVersion::class, 'indicator_version_id');
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
        return $this->belongsTo(ProgrammeUnit::class, 'unit_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
