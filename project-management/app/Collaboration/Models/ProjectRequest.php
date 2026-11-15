<?php

namespace App\Collaboration\Models;

use App\Models\BudgetVersion;
use App\Models\GrantDisbursement;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A request one party of a project puts to another (ADR-0015 §5): funding, a
 * budget revision, a change of activities or dates, information. Approving
 * one changes nothing by itself: what follows goes through the owner's own
 * workflows, and is linked here. Written only by App\Collaboration\Requests.
 *
 * @property string $id
 * @property string $organization_id the project's owner
 * @property string $project_id
 * @property int $number
 * @property string $type
 * @property string $title
 * @property string $justification
 * @property array<string, mixed> $details
 * @property int|null $amount_minor
 * @property string|null $currency
 * @property string|null $submitter_participant_id null: the owner
 * @property string|null $reviewer_participant_id null: the owner
 * @property string $status
 * @property string $raised_by
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decided_by
 * @property string|null $budget_version_id the revision it led to
 * @property string|null $grant_disbursement_id the payment it led to
 * @property CarbonImmutable|null $applied_at
 * @property CarbonImmutable $created_at
 * @property-read Project $project
 * @property-read Participant|null $submitter
 * @property-read Participant|null $reviewer
 * @property-read User $raiser
 * @property-read BudgetVersion|null $budgetVersion
 * @property-read GrantDisbursement|null $disbursement
 */
class ProjectRequest extends Model
{
    use HasUuids;

    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const UNDER_REVIEW = 'under_review';

    public const CHANGES_REQUESTED = 'changes_requested';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::DRAFT => 'Draft',
        self::SUBMITTED => 'Submitted',
        self::UNDER_REVIEW => 'Under review',
        self::CHANGES_REQUESTED => 'Changes requested',
        self::APPROVED => 'Approved',
        self::REJECTED => 'Rejected',
        self::CANCELLED => 'Cancelled',
    ];

    public const EDITABLE = [self::DRAFT, self::CHANGES_REQUESTED];

    public const IN_REVIEW = [self::SUBMITTED, self::UNDER_REVIEW];

    public const TYPES = [
        'funding' => 'Funding / disbursement',
        'budget_revision' => 'Budget revision',
        'activity_change' => 'Activity change',
        'timeline_extension' => 'Timeline extension',
        'information' => 'Information / documents',
    ];

    protected $table = 'project_requests';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'details' => 'array',
            'amount_minor' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function reference(): string
    {
        return 'REQ-'.$this->number;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withoutGlobalScopes();
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function raiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    /**
     * @return BelongsTo<BudgetVersion, $this>
     */
    public function budgetVersion(): BelongsTo
    {
        return $this->belongsTo(BudgetVersion::class)->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<GrantDisbursement, $this>
     */
    public function disbursement(): BelongsTo
    {
        return $this->belongsTo(GrantDisbursement::class, 'grant_disbursement_id')->withoutGlobalScopes();
    }

    /**
     * @return HasMany<RequestDecision, $this>
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(RequestDecision::class, 'request_id')->orderBy('created_at');
    }
}
