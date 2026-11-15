<?php

namespace App\Collaboration\Models;

use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a party must report on a project, how often, to whom (ADR-0015 §4).
 * Its periods are generated once, as reports; ending it keeps them.
 *
 * @property string $id
 * @property string $organization_id the project's owner
 * @property string $project_id
 * @property string|null $responsible_participant_id null: the owner reports
 * @property string|null $reviewer_participant_id null: the owner reviews
 * @property string $category
 * @property string $title
 * @property string $frequency
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property int $due_days
 * @property bool $publish_after_approval
 * @property string $status
 * @property string $created_by
 * @property-read Participant|null $responsible
 * @property-read Participant|null $reviewer
 */
class Obligation extends Model
{
    use HasUuids;

    public const ACTIVE = 'active';

    public const ENDED = 'ended';

    public const FREQUENCIES = ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'annual' => 'Annual', 'once' => 'Once', 'custom' => 'Custom period'];

    protected $table = 'report_obligations';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'ends_on' => 'immutable_date', 'due_days' => 'integer', 'publish_after_approval' => 'boolean'];
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
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(Participant::class, 'responsible_participant_id');
    }

    /**
     * @return BelongsTo<Participant, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Participant::class, 'reviewer_participant_id');
    }

    /**
     * @return HasMany<Report, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class, 'obligation_id');
    }
}
