<?php

namespace App\Mel\Models;

use App\Mel\Models\Concerns\TenantModel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An actual value for one indicator, period and unit, on its way through
 * review. Changes go through App\Mel\Submissions and App\Mel\Review only.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $programme_id
 * @property string $indicator_id
 * @property string $indicator_version_id
 * @property string $reporting_period_id
 * @property string $unit_id
 * @property string|null $value
 * @property string|null $numerator
 * @property string|null $denominator
 * @property string|null $narrative
 * @property string|null $deviation_note
 * @property string $status
 * @property int $revision
 * @property string|null $supersedes_id
 * @property string $source
 * @property string|null $source_reference
 * @property string|null $import_batch_id
 * @property string $created_by
 * @property string|null $submitted_by
 * @property CarbonImmutable|null $submitted_at
 * @property string|null $reviewer_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Programme $programme
 * @property-read Indicator $indicator
 * @property-read IndicatorVersion $version
 * @property-read ReportingPeriod $period
 * @property-read ProgrammeUnit $unit
 * @property-read User $creator
 * @property-read User|null $submitter
 * @property-read User|null $reviewer
 * @property-read Submission|null $supersedes
 * @property-read VerifiedResult|null $verifiedResult
 * @property-read Collection<int, SubmissionValue> $values
 * @property-read Collection<int, ReviewDecision> $decisions
 * @property-read Collection<int, EvidenceFile> $evidence
 */
class Submission extends Model
{
    protected $table = 'mel_submissions';

    use TenantModel;

    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const UNDER_REVIEW = 'under_review';

    public const REJECTED = 'rejected';

    public const VERIFIED = 'verified';

    public const PUBLISHED = 'published';

    public const SUPERSEDED = 'superseded';

    /** Still being worked on: one per indicator, period and unit. */
    public const OPEN = [self::DRAFT, self::SUBMITTED, self::UNDER_REVIEW, self::REJECTED];

    /** Verified, published or not: one standing per indicator, period and unit. */
    public const STANDING = [self::VERIFIED, self::PUBLISHED];

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:6',
            'numerator' => 'decimal:6',
            'denominator' => 'decimal:6',
            'revision' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * The submitter may still change it.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::DRAFT, self::REJECTED], true);
    }

    /**
     * @return BelongsTo<Programme, $this>
     */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * @return BelongsTo<Submission, $this>
     */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(Submission::class, 'supersedes_id');
    }

    /**
     * @return HasOne<VerifiedResult, $this>
     */
    public function verifiedResult(): HasOne
    {
        return $this->hasOne(VerifiedResult::class);
    }

    /**
     * @return HasMany<SubmissionValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(SubmissionValue::class);
    }

    /**
     * @return HasMany<ReviewDecision, $this>
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(ReviewDecision::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return HasMany<EvidenceFile, $this>
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(EvidenceFile::class)->orderBy('created_at');
    }
}
