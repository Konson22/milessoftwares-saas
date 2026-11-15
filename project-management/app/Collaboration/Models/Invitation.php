<?php

namespace App\Collaboration\Models;

use App\Collaboration\Relationship;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invitation to take part in a project: to an organization on Tatook
 * (its representatives answer in the app), or by email to someone whose
 * organization is not here yet (a hashed, expiring token; they accept in the
 * workspace their organization uses). Grants nothing until accepted. Not
 * organization-scoped, as Participant.
 *
 * @property string $id
 * @property string $organization_id the project's owner
 * @property string $project_id
 * @property string $inviting_organization_id
 * @property string|null $inviting_participant_id set when a participant invites a sub-partner
 * @property string $invited_by
 * @property string|null $invited_organization_id
 * @property string|null $email
 * @property string|null $contact_name
 * @property string $relationship
 * @property string|null $parent_participant_id
 * @property list<string> $capabilities
 * @property string|null $message
 * @property string|null $token_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property string|null $accepted_by
 * @property string|null $accepted_organization_id
 * @property string|null $participant_id
 * @property CarbonImmutable|null $declined_at
 * @property string|null $declined_by
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $revoked_by
 * @property CarbonImmutable $created_at
 * @property-read Organization $owner
 * @property-read Organization $invitingOrganization
 * @property-read Organization|null $invitedOrganization
 * @property-read Organization|null $acceptedOrganization
 * @property-read Participant|null $invitingParticipant
 * @property-read Project $project
 * @property-read User $inviter
 */
class Invitation extends Model
{
    use HasUuids;

    public const PENDING = 'pending';

    public const EXPIRED = 'expired';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const REVOKED = 'revoked';

    protected $table = 'project_invitations';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'declined_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Not answered or revoked (it may have expired).
     *
     * @param  Builder<self>  $query
     */
    public function scopeUnanswered(Builder $query): void
    {
        $query->whereNull('accepted_at')->whereNull('declined_at')->whereNull('revoked_at');
    }

    /**
     * Unanswered and in time.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->unanswered()->where('expires_at', '>', now());
    }

    /**
     * The open email invitation a link's token belongs to.
     */
    public static function findOpen(string $token): ?self
    {
        return self::query()->open()->whereNotNull('token_hash')->where('token_hash', self::hashToken($token))->first();
    }

    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => self::ACCEPTED,
            $this->declined_at !== null => self::DECLINED,
            $this->revoked_at !== null => self::REVOKED,
            $this->expires_at->isPast() => self::EXPIRED,
            default => self::PENDING,
        };
    }

    public function isOpen(): bool
    {
        return $this->status() === self::PENDING;
    }

    public function relationshipLabel(): string
    {
        return Relationship::label($this->relationship);
    }

    /** Who it is for, as people read it. */
    public function invitee(): string
    {
        return $this->invitedOrganization->name ?? trim(($this->contact_name ?? '').' <'.($this->email ?? '').'>');
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
    public function invitingOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'inviting_organization_id');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function invitedOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'invited_organization_id');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function acceptedOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'accepted_organization_id');
    }

    /**
     * @return BelongsTo<Participant, $this>
     */
    public function invitingParticipant(): BelongsTo
    {
        return $this->belongsTo(Participant::class, 'inviting_participant_id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
