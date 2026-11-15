<?php

namespace App\Collaboration\Models;

use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A report one party of a project submits to another (ADR-0015 §4): a period
 * of an obligation, or one on its own. Its content is in versions, frozen
 * once submitted; decisions are reviews. Not organization-scoped: written
 * only by App\Collaboration\Reports.
 *
 * @property string $id
 * @property string $organization_id the project's owner
 * @property string $project_id
 * @property string|null $obligation_id
 * @property int $number
 * @property string $category
 * @property string $title
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property CarbonImmutable|null $due_on
 * @property string|null $submitter_participant_id null: the owner
 * @property string|null $reviewer_participant_id null: the owner
 * @property string $status
 * @property string|null $current_version_id
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $published_at
 * @property string|null $published_by
 * @property string $created_by
 * @property CarbonImmutable $created_at
 * @property-read Project $project
 * @property-read Obligation|null $obligation
 * @property-read ReportVersion|null $currentVersion
 * @property-read Participant|null $submitter
 * @property-read Participant|null $reviewer
 */
class Report extends Model
{
    use HasUuids;

    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const UNDER_REVIEW = 'under_review';

    public const CHANGES_REQUESTED = 'changes_requested';

    public const APPROVED = 'approved';

    public const PUBLISHED = 'published';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    public const STATUSES = [
        self::DRAFT => 'Draft',
        self::SUBMITTED => 'Submitted',
        self::UNDER_REVIEW => 'Under review',
        self::CHANGES_REQUESTED => 'Changes requested',
        self::APPROVED => 'Approved',
        self::PUBLISHED => 'Published',
        self::REJECTED => 'Rejected',
        self::WITHDRAWN => 'Withdrawn',
    ];

    /** The submitting party can change it. */
    public const EDITABLE = [self::DRAFT, self::CHANGES_REQUESTED, self::WITHDRAWN];

    /** With the reviewer, waiting for a decision. */
    public const IN_REVIEW = [self::SUBMITTED, self::UNDER_REVIEW];

    /** Approved, published or not: what consolidation may draw on. */
    public const ACCEPTED = [self::APPROVED, self::PUBLISHED];

    public const CATEGORIES = [
        'activity' => 'Activity progress report',
        'monthly' => 'Monthly progress report',
        'quarterly' => 'Quarterly progress report',
        'financial' => 'Financial utilization report',
        'mel' => 'M&E performance report',
        'final' => 'Final project report',
    ];

    protected $table = 'project_reports';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'due_on' => 'immutable_date',
            'submitted_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function reference(): string
    {
        return 'RPT-'.$this->number;
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Obligation, $this>
     */
    public function obligation(): BelongsTo
    {
        return $this->belongsTo(Obligation::class);
    }

    /**
     * @return BelongsTo<ReportVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(ReportVersion::class, 'current_version_id');
    }

    /**
     * @return HasMany<ReportVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ReportVersion::class)->orderBy('number');
    }

    /**
     * @return HasMany<ReportReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(ReportReview::class)->orderBy('created_at');
    }

    /**
     * The approved reports a consolidated one is built from.
     *
     * @return BelongsToMany<Report, $this>
     */
    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'project_report_sources', 'report_id', 'source_report_id');
    }

    /**
     * @return BelongsTo<Participant, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(Participant::class, 'submitter_participant_id');
    }

    /**
     * @return BelongsTo<Participant, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Participant::class, 'reviewer_participant_id');
    }

    public function isOverdue(): bool
    {
        return $this->due_on !== null && in_array($this->status, self::EDITABLE, true) && $this->due_on->lt(today());
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }
}
