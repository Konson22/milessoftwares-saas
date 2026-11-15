<?php

namespace App\Collaboration;

use App\Access\Access;
use App\Collaboration\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Who is acting on a project's reports and requests, and for which party
 * (ADR-0015 §3): a member of the owning organization for the owner, or a
 * member of a participating organization for its participation. Resolved
 * fresh on every request, so suspending or revoking a participation, or
 * removing a representative, takes effect at once.
 */
final readonly class Acting
{
    private function __construct(
        public Project $project,
        public ?Participant $participant,
        public User $user,
        public Access $access,
    ) {}

    /**
     * A member of the owning organization, in its workspace.
     */
    public static function forOwner(Project $project, User $user, Access $access): self
    {
        abort_unless($access->organizationId === $project->organization_id, 404);

        return new self($project, null, $user, $access);
    }

    /**
     * A member of a participating organization who works on the project for
     * it, while every participation up its chain is active.
     */
    public static function forParticipant(Participant $participant, User $user, Access $access): self
    {
        abort_unless(ParticipantAccess::canOpen($participant, $user, $access), 404);
        abort_unless(ParticipantAccess::effective($participant) !== [], 403, 'This participation is not active.');

        $project = app(CurrentOrganization::class)->run($participant->owner, fn () => Project::query()->findOrFail($participant->project_id));

        return new self($project, $participant, $user, $access);
    }

    public function isOwner(): bool
    {
        return $this->participant === null;
    }

    /**
     * The party's id: the participation's, or null for the owner.
     */
    public function partyId(): ?string
    {
        return $this->participant?->id;
    }

    public function organizationId(): string
    {
        return $this->access->organizationId;
    }

    /**
     * Whether this party is the one named (null: the owner).
     */
    public function is(?string $participantId): bool
    {
        return $this->partyId() === $participantId;
    }

    /**
     * The member's own permission, and, for a participant, the matching
     * capability on its participation as it stands now.
     */
    public function may(string $permission, ?string $capability = null): bool
    {
        $held = $this->access->isOwner || $this->access->has($permission);
        if (! $held || $this->participant === null) {
            return $held;
        }

        return $capability !== null && ParticipantAccess::allows($this->participant, $capability);
    }

    /**
     * @throws HttpException
     */
    public function require(string $permission, ?string $capability = null): void
    {
        abort_unless($this->may($permission, $capability), 403);
    }

    /**
     * Whether the member may see the project's recorded budget figures.
     */
    public function seesBudget(): bool
    {
        return $this->may('projects.finance.view', Capability::BUDGET_VIEW);
    }

    /**
     * The party's name, as others read it.
     */
    public function partyName(): string
    {
        return Routing::partyName($this->project, $this->participant);
    }
}
