<?php

namespace App\Collaboration\Models;

use App\Collaboration\Relationship;
use App\Models\Organization;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An organization taking part in another organization's project (ADR-0014).
 * Crosses two organizations, so it is not organization-scoped: every query
 * starts from the owner's project or the participant's organization. Never
 * deleted; written only by App\Collaboration\Collaboration.
 *
 * @property string $id
 * @property string $organization_id the project's owner
 * @property string $project_id
 * @property string $participant_organization_id
 * @property string $relationship
 * @property string|null $parent_participant_id
 * @property list<string> $capabilities
 * @property string $status
 * @property string|null $invitation_id
 * @property CarbonImmutable $joined_at
 * @property CarbonImmutable|null $status_changed_at
 * @property string|null $status_changed_by
 * @property-read Organization $owner
 * @property-read Organization $organization the participating organization
 * @property-read Project $project
 * @property-read Participant|null $parent
 */
class Participant extends Model
{
    use HasUuids;

    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    public const COMPLETED = 'completed';

    public const REVOKED = 'revoked';

    /** Still standing: it can be suspended or brought back. */
    public const LIVE = [self::ACTIVE, self::SUSPENDED];

    /** Ended for good; kept as history. */
    public const ENDED = [self::COMPLETED, self::REVOKED];

    protected $table = 'project_participants';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['capabilities' => 'array', 'joined_at' => 'immutable_datetime', 'status_changed_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'participant_organization_id');
    }

    /**
     * The owner's project. Read across organizations on purpose: the
     * participant's pages run in its own workspace.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Participant, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_participant_id');
    }

    /**
     * @return HasMany<Representative, $this>
     */
    public function representatives(): HasMany
    {
        return $this->hasMany(Representative::class, 'participant_id')->whereNull('removed_at');
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }

    public function relationshipLabel(): string
    {
        return Relationship::label($this->relationship);
    }
}
