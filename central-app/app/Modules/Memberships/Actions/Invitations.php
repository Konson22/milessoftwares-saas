<?php

namespace App\Modules\Memberships\Actions;

use App\Models\User;
use App\Modules\Applications\Models\ApplicationRole;
use App\Modules\Audit\AuditLogger;
use App\Modules\Authorization\Actions\AssignmentDenied;
use App\Modules\Authorization\Actions\AssignRole;
use App\Modules\Authorization\Actions\SeparationOfDutiesWarning;
use App\Modules\Authorization\Support\AccessResolver;
use App\Modules\Authorization\Support\Scope;
use App\Modules\Authorization\Support\WorkspaceAbilities;
use App\Modules\Memberships\Enums\MembershipStatus;
use App\Modules\Memberships\Models\Invitation;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Memberships\Notifications\OrganizationInvitation;
use App\Modules\Memberships\Support\ProposedRoleCheck;
use App\Modules\Organizations\Enums\OrganizationStatus;
use App\Modules\Organizations\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Inviting people into an organization. Invitations are single-use and
 * expire. On acceptance everything is checked again: the invitation row is
 * locked, the email must match the signed-in, verified account, and the
 * inviter must still hold the authority to invite. A proposed role goes
 * through AssignRole as the inviter, so delegation rules apply as usual.
 */
final class Invitations
{
    public const VALID_DAYS = 7;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AccessResolver $access,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Runs inside the inviting organization's tenant context.
     *
     * A proposed role that raises separation-of-duties warnings needs them
     * acknowledged now; the acknowledgement is kept and applied at acceptance.
     *
     * @param  array{role_id: string, scope: array{scope_type: string, branch_id?: string|null, department_id?: string|null}}|null  $proposed
     * @return array{0: Invitation, 1: string} the invitation and its plain token
     *
     * @throws SeparationOfDutiesWarning
     */
    public function invite(OrganizationMembership $inviter, string $email, ?array $proposed = null, bool $acknowledgeSeparationOfDuties = false): array
    {
        $this->assertMayInvite($inviter);

        $email = mb_strtolower(trim($email));
        $organization = $this->tenant->require();

        $alreadyMember = OrganizationMembership::query()
            ->where('status', MembershipStatus::Active)
            ->whereHas('user', fn ($query) => $query->whereRaw('lower(email) = ?', [$email]))
            ->exists();

        if ($alreadyMember) {
            throw new MembershipActionDenied('That person is already a member.');
        }

        if ($proposed !== null) {
            $this->assertProposalPlausible($inviter, $proposed);
            $proposed['acknowledge_sod'] = app(ProposedRoleCheck::class)->separationOfDuties($proposed, $acknowledgeSeparationOfDuties);
        }

        $token = Str::random(48);

        try {
            $invitation = new Invitation;
            $invitation->forceFill([
                'email' => $email,
                'token_hash' => Invitation::hashToken($token),
                'invited_by_membership_id' => $inviter->getKey(),
                'proposed_access' => $proposed,
                'status' => 'pending',
                'expires_at' => now()->addDays(self::VALID_DAYS),
            ])->save();
        } catch (QueryException $e) {
            if ($e->getCode() === '23505' && str_contains($e->getMessage(), 'invitations_one_pending_per_email')) {
                throw new MembershipActionDenied('That person already has a pending invitation. Resend it instead.');
            }

            throw $e;
        }

        $this->send($invitation, $organization, $inviter, $token);
        $this->record('invitation.created', $invitation, $inviter->user, ['email' => $email, 'proposed_access' => $proposed]);

        return [$invitation, $token];
    }

    /**
     * Issues a new token and expiry; the old link stops working.
     *
     * @return string the new plain token
     */
    public function resend(OrganizationMembership $actor, Invitation $invitation): string
    {
        $this->assertMayInvite($actor);

        if ($invitation->effectiveStatus() !== 'pending' && $invitation->effectiveStatus() !== 'expired') {
            throw new MembershipActionDenied('Only open invitations can be resent.');
        }

        $token = Str::random(48);
        $invitation->forceFill([
            'token_hash' => Invitation::hashToken($token),
            'status' => 'pending',
            'expires_at' => now()->addDays(self::VALID_DAYS),
        ])->save();

        $this->send($invitation, $this->tenant->require(), $actor, $token);
        $this->record('invitation.resent', $invitation, $actor->user);

        return $token;
    }

    public function revoke(OrganizationMembership $actor, Invitation $invitation): void
    {
        $this->assertMayInvite($actor);

        if ($invitation->status !== 'pending') {
            return;
        }

        $invitation->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();
        $this->record('invitation.revoked', $invitation, $actor->user);
    }

    /**
     * Looks up an invitation by its plain token, across organizations: the
     * token is the only thing the invitee has.
     */
    public function findByToken(string $token): ?Invitation
    {
        return Invitation::acrossTenants()->where('token_hash', Invitation::hashToken($token))->first();
    }

    /**
     * @return array{membership: OrganizationMembership, organization: Organization, roleNote: string|null}
     *
     * @throws MembershipActionDenied
     */
    public function accept(User $user, string $token): array
    {
        return DB::transaction(function () use ($user, $token): array {
            // Locking the row makes acceptance single-use under concurrency.
            $invitation = Invitation::acrossTenants()
                ->where('token_hash', Invitation::hashToken($token))
                ->lockForUpdate()
                ->first();

            if ($invitation === null || $invitation->status !== 'pending') {
                throw new MembershipActionDenied('This invitation is no longer valid.');
            }

            // Rows are marked expired by `platform:expire-invitations`; the
            // timestamp is what counts here.
            if ($invitation->expires_at->isPast()) {
                throw new MembershipActionDenied('This invitation has expired. Ask for a new one.');
            }

            if (mb_strtolower($user->email) !== mb_strtolower($invitation->email)) {
                throw new MembershipActionDenied('This invitation was sent to a different email address.');
            }

            if ($user->email_verified_at === null) {
                throw new MembershipActionDenied('Verify your email address before accepting.');
            }

            $organization = Organization::query()->findOrFail($invitation->organization_id);

            if ($organization->status !== OrganizationStatus::Active) {
                throw new MembershipActionDenied('This organization is not accepting new members right now.');
            }

            return $this->tenant->run($organization, function () use ($invitation, $user, $organization): array {
                $inviter = OrganizationMembership::query()->with('organization')->find($invitation->invited_by_membership_id);

                if ($inviter === null || ! $inviter->isActive() || ! (new WorkspaceAbilities($this->access))->manageAccess($inviter)) {
                    throw new MembershipActionDenied('The person who invited you can no longer add members. Ask for a new invitation.');
                }

                $membership = $this->joinOrganization($user);

                $invitation->forceFill([
                    'status' => 'accepted',
                    'accepted_at' => now(),
                    'accepted_by_user_id' => $user->getKey(),
                ])->save();

                $roleNote = $this->applyProposedAccess($invitation, $inviter, $membership);

                $this->record('invitation.accepted', $invitation, $user, [
                    'membership_id' => $membership->getKey(),
                    'role_note' => $roleNote,
                ]);

                return ['membership' => $membership, 'organization' => $organization, 'roleNote' => $roleNote];
            });
        });
    }

    private function joinOrganization(User $user): OrganizationMembership
    {
        $membership = OrganizationMembership::query()->where('user_id', $user->getKey())->first();

        if ($membership !== null && $membership->isActive()) {
            throw new MembershipActionDenied('You are already a member of this organization.');
        }

        if ($membership !== null && $membership->status === MembershipStatus::Suspended) {
            throw new MembershipActionDenied('Your membership of this organization is suspended.');
        }

        // A removed member who is invited back is reactivated (one membership
        // per user and organization).
        $membership ??= new OrganizationMembership;
        $membership->forceFill([
            'user_id' => $user->getKey(),
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
            'removed_at' => null,
        ])->save();

        return $membership;
    }

    /**
     * Applies the proposed role as the inviter. A refusal does not undo the
     * membership; it is reported and audited instead.
     */
    private function applyProposedAccess(Invitation $invitation, OrganizationMembership $inviter, OrganizationMembership $membership): ?string
    {
        $proposed = $invitation->proposed_access;

        if ($proposed === null) {
            return null;
        }

        $role = ApplicationRole::query()->with('application')->find($proposed['role_id']);

        if ($role === null) {
            return 'The proposed role no longer exists.';
        }

        try {
            app(AssignRole::class)->handle(
                $inviter,
                $membership,
                $role,
                Scope::fromInput($proposed['scope']),
                acknowledgeSeparationOfDuties: (bool) ($proposed['acknowledge_sod'] ?? false),
            );
        } catch (AssignmentDenied|SeparationOfDutiesWarning|InvalidArgumentException $e) {
            return 'The proposed role was not given: '.$e->getMessage();
        }

        return null;
    }

    private function assertMayInvite(OrganizationMembership $membership): void
    {
        if (! (new WorkspaceAbilities($this->access))->manageAccess($membership)) {
            throw new MembershipActionDenied('You cannot invite people to this organization.');
        }
    }

    /**
     * Early feedback for the inviter. The authoritative check is AssignRole at
     * acceptance time.
     *
     * @param  array{role_id: string, scope: array{scope_type: string, branch_id?: string|null, department_id?: string|null}}  $proposed
     */
    private function assertProposalPlausible(OrganizationMembership $inviter, array $proposed): void
    {
        $role = ApplicationRole::query()->with('application')->find($proposed['role_id']);

        try {
            $scope = Scope::fromInput($proposed['scope']);
        } catch (InvalidArgumentException $e) {
            throw new MembershipActionDenied($e->getMessage());
        }

        if ($role === null || $role->application->current_catalog_version_id !== $role->catalog_version_id) {
            throw new MembershipActionDenied('Choose a current role.');
        }

        if (! $role->allowsScope($scope->type)) {
            throw new MembershipActionDenied("{$role->name} cannot be given at that scope.");
        }

        if (! $this->access->isOwner($inviter) && ! $this->access->holds($inviter, 'platform.access.manage', $scope)) {
            throw new MembershipActionDenied('You cannot manage access at that scope.');
        }
    }

    private function send(Invitation $invitation, Organization $organization, OrganizationMembership $inviter, string $token): void
    {
        Notification::route('mail', $invitation->email)->notify(new OrganizationInvitation(
            $organization->display_name,
            $inviter->user->name,
            $token,
            $invitation->expires_at,
        ));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(string $action, Invitation $invitation, ?User $actor, array $context = []): void
    {
        $this->audit->record($action, $invitation, $context, actor: $actor, organizationId: $invitation->organization_id);
    }
}
