<?php

namespace App\Collaboration;

use App\Access\Access;
use App\Access\AccessResolver;
use App\Actions\Alerts;
use App\Audit\Audit;
use App\Collaboration\Models\Invitation;
use App\Collaboration\Models\Participant;
use App\Collaboration\Models\Representative;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\InvitedToProject;
use App\Tenancy\CurrentOrganization;
use App\Tenancy\Hosts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Organizations taking part in each other's projects (ADR-0014): invitations,
 * participation and who represents a participant. Authority is checked here,
 * not only in routes: when an invitation is made, when capabilities change,
 * and again when it is accepted. Everything is audited on each side.
 */
final class Collaboration
{
    /**
     * Invites an organization (on Tatook) or an email address to the project.
     * $as is the participation a sub-partner is invited under; null when the
     * owner invites.
     *
     * @param  array{organization_id?: string|null, email?: string|null, contact_name?: string|null, relationship: string, parent_participant_id?: string|null, capabilities: array<array-key, mixed>, message?: string|null}  $details
     *
     * @throws ValidationException
     */
    public static function invite(Project $project, User $by, Access $access, ?Participant $as, array $details): Invitation
    {
        abort_unless($project->isOpen(), 409, 'This project is closed: nobody new can join it.');
        $capabilities = Capability::normalize($details['capabilities']);
        $relationship = $details['relationship'];
        if (! in_array($relationship, Relationship::keys(), true)) {
            throw ValidationException::withMessages(['relationship' => 'Choose how they take part.']);
        }

        if ($as === null) {
            abort_unless($access->organizationId === $project->organization_id && ($access->isOwner || $access->has('projects.partners.manage')), 403);
            self::refuseBeyond($capabilities, Capability::grantableBy($access));
            $parent = self::parentFor($project, $details['parent_participant_id'] ?? null);
            $inviting = $project->organization_id;
        } else {
            abort_unless($as->project_id === $project->id && ParticipantAccess::canInviteFor($as, $access), 403);
            self::refuseBeyond($capabilities, ParticipantAccess::effective($as));
            $parent = $as;
            $inviting = $as->participant_organization_id;
        }

        return DB::transaction(function () use ($project, $by, $as, $details, $capabilities, $relationship, $parent, $inviting) {
            // One at a time per project, so two invitations can't both pass the checks.
            Project::query()->withoutGlobalScopes()->whereKey($project->id)->lockForUpdate()->first();

            $organization = null;
            $email = null;
            if (($details['organization_id'] ?? null) !== null) {
                $organization = Organization::query()->find($details['organization_id']);
                if ($organization === null || ! $organization->isActive()) {
                    throw ValidationException::withMessages(['organization_id' => 'That organization is not active on Tatook.']);
                }
                if ($organization->id === $project->organization_id || $organization->id === $inviting) {
                    throw ValidationException::withMessages(['organization_id' => 'That organization already runs this project.']);
                }
                if (self::takesPart($project, $organization->id)) {
                    throw ValidationException::withMessages(['organization_id' => "{$organization->name} already takes part in this project."]);
                }
                if (Invitation::query()->where('project_id', $project->id)->where('invited_organization_id', $organization->id)->open()->exists()) {
                    throw ValidationException::withMessages(['organization_id' => "{$organization->name} has an invitation waiting already. Resend it instead."]);
                }
                // One that expired unanswered gives way to the new one (the database allows
                // one unanswered invitation per project and organization). Kept, closed, audited.
                foreach (Invitation::query()->where('project_id', $project->id)->where('invited_organization_id', $organization->id)->unanswered()->get() as $expired) {
                    $expired->forceFill(['revoked_at' => now()])->save();
                    Audit::record('collaboration.invitation_superseded', $expired, ['project' => $project->code, 'invitee' => $organization->name, 'expired_at' => $expired->expires_at->toIso8601String()], $project->organization_id, $by);
                }
            } else {
                $email = mb_strtolower(trim((string) ($details['email'] ?? '')));
                if ($email === '' || trim((string) ($details['contact_name'] ?? '')) === '') {
                    throw ValidationException::withMessages(['email' => 'Choose an organization, or give a contact name and email address.']);
                }
                if (Invitation::query()->where('project_id', $project->id)->where('email', $email)->open()->exists()) {
                    throw ValidationException::withMessages(['email' => 'That address has an invitation waiting already. Resend it instead.']);
                }
            }

            $token = $email === null ? null : Str::random(48);
            $invitation = new Invitation;
            $invitation->forceFill([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'inviting_organization_id' => $inviting,
                'inviting_participant_id' => $as?->id,
                'invited_by' => $by->id,
                'invited_organization_id' => $organization?->id,
                'email' => $email,
                'contact_name' => $email === null ? null : trim((string) ($details['contact_name'] ?? '')),
                'relationship' => $relationship,
                'parent_participant_id' => $parent?->id,
                'capabilities' => $capabilities,
                'message' => self::text($details['message'] ?? null),
                'token_hash' => $token === null ? null : Invitation::hashToken($token),
                'expires_at' => now()->addDays(self::days()),
            ])->save();

            $properties = ['project' => $project->code, 'invitee' => $organization->name ?? $email, 'relationship' => $relationship, 'capabilities' => $capabilities];
            Audit::record('collaboration.invited', $invitation, $properties, $project->organization_id, $by);
            if ($inviting !== $project->organization_id) {
                Audit::record('collaboration.invited', $invitation, $properties, $inviting, $by);
            }

            if ($organization !== null) {
                Audit::record('collaboration.invitation_received', $invitation, ['project' => $project->name, 'from' => $invitation->invitingOrganization->name], $organization->id, $by);
                self::tellManagers($organization, $by, "{$invitation->invitingOrganization->name} invites you to the project {$project->name}", 'Accept or decline in Shared projects. Nothing is shared before you accept.');
            } else {
                self::mail($invitation, (string) $token, $by);
            }

            return $invitation;
        });
    }

    /**
     * An authorized representative of $organization accepts. For an email
     * invitation the controller found it by its token; a direct one must be
     * for $organization. The inviter's authority is checked again: an
     * invitation outlives nobody's permissions.
     *
     * @throws ValidationException
     */
    public static function accept(Invitation $invitation, Organization $organization, User $by, Access $access): Participant
    {
        abort_unless($access->organizationId === $organization->id && ($access->isOwner || $access->has('projects.partners.manage')), 403);

        return DB::transaction(function () use ($invitation, $organization, $by) {
            $invitation = Invitation::query()->lockForUpdate()->findOrFail($invitation->id);
            abort_unless($invitation->isOpen(), 409, 'This invitation has been answered, withdrawn or has expired.');
            abort_if($invitation->invited_organization_id !== null && $invitation->invited_organization_id !== $organization->id, 404);

            $project = Project::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($invitation->project_id);
            abort_unless($project->isOpen(), 409, 'This project is closed: nobody new can join it.');
            if ($organization->id === $project->organization_id || $organization->id === $invitation->inviting_organization_id) {
                throw ValidationException::withMessages(['invitation' => 'Your organization already runs this project.']);
            }
            if (self::takesPart($project, $organization->id)) {
                throw ValidationException::withMessages(['invitation' => 'Your organization already takes part in this project.']);
            }
            if (! self::inviterStillMay($invitation)) {
                throw ValidationException::withMessages(['invitation' => 'Whoever invited you can no longer give this access. Ask them for a new invitation.']);
            }

            $participant = new Participant;
            $participant->forceFill([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'participant_organization_id' => $organization->id,
                'relationship' => $invitation->relationship,
                'parent_participant_id' => $invitation->parent_participant_id,
                'capabilities' => Capability::normalize($invitation->capabilities),
                'status' => Participant::ACTIVE,
                'invitation_id' => $invitation->id,
                'joined_at' => now(),
            ])->save();

            $invitation->forceFill([
                'accepted_at' => now(),
                'accepted_by' => $by->id,
                'accepted_organization_id' => $organization->id,
                'participant_id' => $participant->id,
                'token_hash' => null,
            ])->save();

            // Whoever accepted works on it for their organization, to begin with.
            $representative = new Representative;
            $representative->forceFill(['participant_id' => $participant->id, 'user_id' => $by->id, 'responsibilities' => ['partner_representative'], 'added_by' => $by->id])->save();

            $properties = ['project' => $project->code, 'organization' => $organization->name, 'relationship' => $participant->relationship, 'capabilities' => $participant->capabilities];
            Audit::record('collaboration.joined', $participant, $properties, $project->organization_id, $by);
            if ($invitation->inviting_organization_id !== $project->organization_id) {
                Audit::record('collaboration.joined', $participant, $properties, $invitation->inviting_organization_id, $by);
            }
            Audit::record('collaboration.accepted', $participant, ['project' => $project->name, 'owner' => $participant->owner->name], $organization->id, $by);

            self::tellManagers($invitation->invitingOrganization, $by, "{$organization->name} accepted your invitation to {$project->name}", null);

            return $participant;
        });
    }

    public static function decline(Invitation $invitation, Organization $organization, User $by, Access $access): void
    {
        abort_unless($access->organizationId === $organization->id && ($access->isOwner || $access->has('projects.partners.manage')), 403);
        abort_if($invitation->invited_organization_id !== null && $invitation->invited_organization_id !== $organization->id, 404);
        abort_unless($invitation->isOpen(), 409, 'This invitation has been answered, withdrawn or has expired.');

        $invitation->forceFill(['declined_at' => now(), 'declined_by' => $by->id, 'token_hash' => null])->save();

        Audit::record('collaboration.declined', $invitation, ['organization' => $organization->name], $invitation->organization_id, $by);
        if ($invitation->inviting_organization_id !== $invitation->organization_id) {
            Audit::record('collaboration.declined', $invitation, ['organization' => $organization->name], $invitation->inviting_organization_id, $by);
        }
        Audit::record('collaboration.invitation_declined', $invitation, ['project' => $invitation->project->name], $organization->id, $by);
        self::tellManagers($invitation->invitingOrganization, $by, "{$organization->name} declined your invitation to {$invitation->project->name}", null);
    }

    /**
     * Withdraws an invitation not yet answered. The owner's managers may
     * withdraw any; a participant only those it sent.
     */
    public static function revoke(Invitation $invitation, User $by, Access $access): void
    {
        self::authorizeInvitation($invitation, $access);
        abort_unless($invitation->accepted_at === null && $invitation->declined_at === null && $invitation->revoked_at === null, 409, 'This invitation has been answered or withdrawn already.');

        $invitation->forceFill(['revoked_at' => now(), 'revoked_by' => $by->id, 'token_hash' => null])->save();
        Audit::record('collaboration.invitation_revoked', $invitation, ['invitee' => $invitation->invitee()], $invitation->organization_id, $by);
        if ($invitation->inviting_organization_id !== $invitation->organization_id) {
            Audit::record('collaboration.invitation_revoked', $invitation, ['invitee' => $invitation->invitee()], $invitation->inviting_organization_id, $by);
        }
    }

    /**
     * More time, waiting or expired. An emailed one gets a new link (the
     * old one stops working); a direct one is told again.
     */
    public static function resend(Invitation $invitation, User $by, Access $access): void
    {
        self::authorizeInvitation($invitation, $access);
        // Sent again in the sender's name, so only from the side that sent it.
        abort_unless($access->organizationId === $invitation->inviting_organization_id, 403);
        abort_unless($invitation->accepted_at === null && $invitation->declined_at === null && $invitation->revoked_at === null, 409, 'Only an invitation still waiting, or one that expired, can be sent again.');
        if (! self::inviterStillMay($invitation, $by)) {
            abort(409, 'This invitation gives more than its sender may give now. Withdraw it and send a new one.');
        }

        DB::transaction(function () use ($invitation, $by) {
            $invitation = Invitation::query()->lockForUpdate()->findOrFail($invitation->id);
            $token = $invitation->email === null ? null : Str::random(48);
            $invitation->forceFill([
                'expires_at' => now()->addDays(self::days()),
                'invited_by' => $by->id,
                'token_hash' => $token === null ? null : Invitation::hashToken($token),
            ])->save();

            Audit::record('collaboration.invitation_resent', $invitation, ['invitee' => $invitation->invitee()], $invitation->organization_id, $by);
            if ($token !== null) {
                self::mail($invitation, $token, $by);
            } elseif ($invitation->invitedOrganization !== null) {
                self::tellManagers($invitation->invitedOrganization, $by, "Reminder: {$invitation->invitingOrganization->name} invites you to the project {$invitation->project->name}", null);
            }
        });
    }

    /**
     * New capabilities, within the authority of whoever changes them: the
     * owner's managers, or the participation it works under.
     *
     * @param  array<array-key, mixed>  $capabilities
     */
    public static function changeCapabilities(Participant $participant, array $capabilities, User $by, Access $access, ?Participant $as = null): void
    {
        abort_unless($participant->isLive(), 409, 'This participation has ended.');
        $ceiling = self::authorityOver($participant, $access, $as);
        $capabilities = Capability::normalize($capabilities);
        $before = Capability::normalize($participant->capabilities);
        // Taking away is always allowed; what is added must be theirs to give.
        self::refuseBeyond(array_values(array_diff($capabilities, $before)), $ceiling);

        $participant->forceFill(['capabilities' => $capabilities])->save();
        Audit::change('collaboration.capabilities_changed', $participant, ['capabilities' => $before], ['capabilities' => $capabilities], ['organization' => $participant->organization->name], $participant->organization_id, $by);
        Audit::record('collaboration.capabilities_changed', $participant, ['project' => $participant->project->name, 'capabilities' => $capabilities], $participant->participant_organization_id, $by);
    }

    /**
     * Suspends, brings back, completes or revokes. Completed and revoked are
     * final and keep everything recorded so far; those under it lose access
     * with it (ParticipantAccess::effective), and invitations it sent and
     * nobody answered are withdrawn.
     */
    public static function setStatus(Participant $participant, string $status, User $by, Access $access, ?Participant $as = null): void
    {
        self::authorityOver($participant, $access, $as);
        $allowed = match ($participant->status) {
            Participant::ACTIVE => [Participant::SUSPENDED, Participant::COMPLETED, Participant::REVOKED],
            Participant::SUSPENDED => [Participant::ACTIVE, Participant::COMPLETED, Participant::REVOKED],
            default => [],
        };
        abort_unless(in_array($status, $allowed, true), 409, 'This participation cannot change that way.');

        DB::transaction(function () use ($participant, $status, $by) {
            $before = $participant->status;
            $participant->forceFill(['status' => $status, 'status_changed_at' => now(), 'status_changed_by' => $by->id])->save();
            if (in_array($status, Participant::ENDED, true)) {
                Invitation::query()->where('inviting_participant_id', $participant->id)->unanswered()
                    ->update(['revoked_at' => now(), 'revoked_by' => $by->id, 'token_hash' => null]);
            }

            // Its tasks stay the owner's: those still open are counted, for the owner to give to someone else.
            $open = in_array($status, Participant::ENDED, true) ? SharedTasks::openFor($participant) : null;
            Audit::change('collaboration.status_changed', $participant, ['status' => $before], ['status' => $status], ['organization' => $participant->organization->name, 'open_tasks_left' => $open], $participant->organization_id, $by);
            Audit::record('collaboration.status_changed', $participant, ['project' => $participant->project->name, 'status' => $status], $participant->participant_organization_id, $by);
            self::tellManagers($participant->organization, $by, "Your participation in {$participant->project->name} is now {$status}", null);
        });
    }

    /**
     * A member of the participating organization starts working on the
     * project for it. Only its collaboration managers choose.
     *
     * @param  array<array-key, mixed>|null  $responsibilities
     *
     * @throws ValidationException
     */
    public static function addRepresentative(Participant $participant, User $member, ?array $responsibilities, User $by, Access $access): Representative
    {
        self::authorizeOwnSide($participant, $access);
        abort_unless($participant->isLive(), 409, 'This participation has ended.');
        $membership = OrganizationMembership::query()->where('organization_id', $participant->participant_organization_id)->where('user_id', $member->id)->first();
        if ($membership === null || ! $membership->isActive()) {
            throw ValidationException::withMessages(['user_id' => 'Choose an active member of your organization.']);
        }

        return DB::transaction(function () use ($participant, $member, $responsibilities, $by) {
            Participant::query()->whereKey($participant->id)->lockForUpdate()->first();
            if (ParticipantAccess::isRepresentative($participant, $member)) {
                throw ValidationException::withMessages(['user_id' => "{$member->name} works on this project already."]);
            }
            $representative = new Representative;
            $representative->forceFill([
                'participant_id' => $participant->id,
                'user_id' => $member->id,
                'responsibilities' => Responsibility::normalize($responsibilities),
                'added_by' => $by->id,
            ])->save();

            Audit::record('collaboration.representative_added', $representative, ['project' => $participant->project->name, 'user_id' => $member->id], $participant->participant_organization_id, $by);
            Audit::record('collaboration.representative_added', $representative, ['organization' => $participant->organization->name, 'name' => $member->name], $participant->organization_id, $by);

            return $representative;
        });
    }

    public static function removeRepresentative(Representative $representative, User $by, Access $access): void
    {
        $participant = $representative->participant;
        self::authorizeOwnSide($participant, $access);
        abort_unless($representative->removed_at === null, 409, 'They no longer work on this project.');

        $representative->forceFill(['removed_at' => now(), 'removed_by' => $by->id])->save();
        Audit::record('collaboration.representative_removed', $representative, ['project' => $participant->project->name, 'user_id' => $representative->user_id], $participant->participant_organization_id, $by);
        Audit::record('collaboration.representative_removed', $representative, ['organization' => $participant->organization->name, 'name' => $representative->user->name], $participant->organization_id, $by);
    }

    /**
     * Whether the organization owns the project or takes part in it now.
     */
    public static function takesPart(Project $project, string $organizationId): bool
    {
        return $project->organization_id === $organizationId
            || Participant::query()->where('project_id', $project->id)->where('participant_organization_id', $organizationId)->whereIn('status', Participant::LIVE)->exists();
    }

    /**
     * The most the member may grant to this participation: the owner's
     * managers what their permissions imply; the participation it works
     * under, what that one holds.
     *
     * @return list<string>
     */
    public static function authorityOver(Participant $participant, Access $access, ?Participant $as = null): array
    {
        if ($as === null) {
            abort_unless($access->organizationId === $participant->organization_id && ($access->isOwner || $access->has('projects.partners.manage')), 403);

            return Capability::grantableBy($access);
        }

        abort_unless($participant->parent_participant_id === $as->id && ParticipantAccess::canInviteFor($as, $access), 403);

        return ParticipantAccess::effective($as);
    }

    /**
     * The owner's managers, or the participant that sent it.
     */
    private static function authorizeInvitation(Invitation $invitation, Access $access): void
    {
        if ($access->organizationId === $invitation->organization_id) {
            abort_unless($access->isOwner || $access->has('projects.partners.manage'), 403);

            return;
        }

        $sender = $invitation->invitingParticipant;
        abort_unless($sender !== null && $access->organizationId === $sender->participant_organization_id && $access->has('projects.partners.manage'), 403);
    }

    /**
     * The participating organization's own collaboration managers.
     */
    private static function authorizeOwnSide(Participant $participant, Access $access): void
    {
        abort_unless($access->organizationId === $participant->participant_organization_id && ($access->isOwner || $access->has('projects.partners.manage')), 403);
    }

    /**
     * The sender's authority now, from the access kept on their membership
     * (the owner's side) or the participation they sent it for: still
     * allowed to invite, and to give everything the invitation gives. $by,
     * when someone else acts on it (resending), must hold it too.
     */
    private static function inviterStillMay(Invitation $invitation, ?User $by = null): bool
    {
        $capabilities = Capability::normalize($invitation->capabilities);
        $sender = $by ?? $invitation->inviter;
        $membership = OrganizationMembership::query()->where('organization_id', $invitation->inviting_organization_id)->where('user_id', $sender->id)->first();
        if ($membership === null || ! $membership->isActive() || ! $sender->isActive()) {
            return false;
        }
        $access = (new AccessResolver)->for($membership);
        if (! $access->isOwner && ! $access->has('projects.partners.manage')) {
            return false;
        }

        if ($invitation->inviting_participant_id === null) {
            if ($invitation->parent_participant_id !== null && ! Participant::query()->whereKey($invitation->parent_participant_id)->whereIn('status', Participant::LIVE)->exists()) {
                return false;
            }

            return array_diff($capabilities, Capability::grantableBy($access)) === [];
        }

        $participant = Participant::query()->find($invitation->inviting_participant_id);

        return $participant !== null
            && ParticipantAccess::allows($participant, Capability::PARTNERS_MANAGE)
            && array_diff($capabilities, ParticipantAccess::effective($participant)) === [];
    }

    /**
     * @param  list<string>  $capabilities
     * @param  list<string>  $ceiling
     *
     * @throws ValidationException
     */
    private static function refuseBeyond(array $capabilities, array $ceiling): void
    {
        $beyond = array_values(array_diff($capabilities, $ceiling));
        if ($beyond !== []) {
            throw ValidationException::withMessages(['capabilities' => 'You cannot give: '.implode(', ', Capability::labels($beyond)).'.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private static function parentFor(Project $project, ?string $parentId): ?Participant
    {
        if ($parentId === null || $parentId === '') {
            return null;
        }
        $parent = Participant::query()->where('project_id', $project->id)->whereIn('status', Participant::LIVE)->find($parentId);
        if ($parent === null) {
            throw ValidationException::withMessages(['parent_participant_id' => 'Choose an organization taking part in this project.']);
        }

        return $parent;
    }

    private static function tellManagers(Organization $organization, User $by, string $title, ?string $excerpt): void
    {
        if (! $organization->isActive()) {
            return;
        }

        app(CurrentOrganization::class)->run($organization, fn () => Alerts::tell(
            Alerts::holders('projects.partners.manage'),
            UserNotification::MESSAGE,
            $title,
            $excerpt,
            route('shared-projects.index', [], false),
            $by,
        ));
    }

    private static function mail(Invitation $invitation, string $token, User $by): void
    {
        Notification::route('mail', (string) $invitation->email)->notify(new InvitedToProject(
            inviting: $invitation->invitingOrganization->name,
            inviter: $by->name,
            project: $invitation->project->name,
            relationship: Relationship::label($invitation->relationship),
            message: $invitation->message,
            url: Hosts::organization($invitation->owner, route('collaboration.landing', ['token' => $token], false)),
            expiresAt: $invitation->expires_at,
        ));
    }

    private static function days(): int
    {
        return (int) config('access.invitation_days', 7);
    }

    private static function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 2000);
    }
}
