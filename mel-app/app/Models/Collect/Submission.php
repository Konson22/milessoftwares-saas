<?php

namespace App\Models\Collect;

use App\Models\Concerns\TenantModel;
use App\Models\Programme;
use App\Models\ProgrammeUnit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One filled-in form, as collected (ADR-0012 §6, §10). The id is the
 * phone's: sent again, it is the same submission. The original answers
 * never change; a correction is a revision, and the current answers are
 * the latest revision's.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $form_id
 * @property string $form_version_id
 * @property string|null $programme_id
 * @property string|null $programme_unit_id
 * @property string $author_id
 * @property string $status
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable $completed_at
 * @property CarbonImmutable $received_at
 * @property array<string, mixed> $answers
 * @property string $content_hash
 * @property list<array{question: string, sha256: string, size: int, mime: string}> $attachments_manifest
 * @property int $attachments_expected
 * @property int $attachments_received
 * @property bool|null $consent_given
 * @property string|null $lat
 * @property string|null $lng
 * @property string|null $accuracy
 * @property string|null $app_version
 * @property string|null $current_revision_id
 * @property string|null $reviewer_id
 * @property CarbonImmutable|null $reviewed_at
 * @property CarbonImmutable|null $purged_at
 * @property-read Form $form
 * @property-read FormVersion $version
 * @property-read Programme|null $programme
 * @property-read ProgrammeUnit|null $unit
 * @property-read User $author
 * @property-read User|null $reviewer
 * @property-read SubmissionRevision|null $currentRevision
 * @property-read Collection<int, SubmissionRevision> $revisions
 * @property-read Collection<int, Attachment> $attachments
 * @property-read Collection<int, ReviewDecision> $decisions
 */
class Submission extends Model
{
    use TenantModel;

    protected $table = 'collect_submissions';

    public const RECEIVING = 'receiving';

    public const SUBMITTED = 'submitted';

    public const UNDER_REVIEW = 'under_review';

    public const CORRECTION_REQUESTED = 'correction_requested';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const STATUSES = [self::RECEIVING, self::SUBMITTED, self::UNDER_REVIEW, self::CORRECTION_REQUESTED, self::ACCEPTED, self::REJECTED];

    /** Waiting for a reviewer. */
    public const TO_REVIEW = [self::SUBMITTED, self::UNDER_REVIEW];

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'answers' => 'array',
            'attachments_manifest' => 'array',
            'attachments_expected' => 'integer',
            'attachments_received' => 'integer',
            'consent_given' => 'boolean',
            'reviewed_at' => 'immutable_datetime',
            'purged_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * The answers as they stand: the latest correction, or the original.
     *
     * @return array<string, mixed>
     */
    public function currentAnswers(): array
    {
        return $this->currentRevision === null ? $this->answers : $this->currentRevision->answers;
    }

    /**
     * "Household survey · 0193a2f4".
     */
    public function label(): string
    {
        return $this->form->name.' · '.substr($this->id, 0, 8);
    }

    /**
     * @return BelongsTo<Form, $this>
     */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /**
     * @return BelongsTo<FormVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'form_version_id');
    }

    /**
     * @return BelongsTo<Programme, $this>
     */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    /**
     * @return BelongsTo<ProgrammeUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProgrammeUnit::class, 'programme_unit_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * @return BelongsTo<SubmissionRevision, $this>
     */
    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(SubmissionRevision::class, 'current_revision_id');
    }

    /**
     * @return HasMany<SubmissionRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(SubmissionRevision::class)->orderBy('number');
    }

    /**
     * @return HasMany<Attachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /**
     * @return HasMany<ReviewDecision, $this>
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(ReviewDecision::class)->orderBy('created_at');
    }
}
