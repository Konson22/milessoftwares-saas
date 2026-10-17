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
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Memberships\Support\ProposedRoleCheck;
use App\Modules\Organizations\Enums\OrganizationStatus;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Members an administrator adds directly, for people who should start
 * working without an invitation email. The administrator gets a temporary
 * password to hand over; on first sign-in the person must choose their own,
 * and the temporary one stops working after VALID_DAYS.
 *
 * Only new accounts: an administrator never sets the password of an account
 * that already exists (that person is invited instead). The email is the
 * administrator's word, not proven, so the account is marked as created by
 * this organization (see User::hasVerifiedEmail()).
 *
 * Runs inside the organization's tenant context; the same people who may
 * invite may add members.
 */
final class TemporaryAccounts
{
    public const VALID_DAYS = 7;

    /** No 0/O, 1/l/I: the password is read out or written down. */
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AccessResolver $access,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{role_id: string, scope: array{scope_type: string, branch_id?: string|null, department_id?: string|null}}|null  $proposed
     * @return array{membership: OrganizationMembership, password: string, expiresAt: CarbonInterface, roleNote: string|null}
     *
     * @throws MembershipActionDenied
     * @throws SeparationOfDutiesWarning before anything is created, when the role warns and it was not acknowledged
     */
    public function add(OrganizationMembership $actor, string $name, string $email, ?array $proposed = null, bool $acknowledgeSeparationOfDuties = false): array
    {
        $this->assertMayAdd($actor);

        $organization = $this->tenant->require();
        if ($organization->status !== OrganizationStatus::Active) {
            throw new MembershipActionDenied('This organization is not accepting new members right now.');
        }

        $email = mb_strtolower(trim($email));
        if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw new MembershipActionDenied('An account with that email already exists. Invite them instead: they sign in with their own password.');
        }

        $acknowledged = $proposed !== null && app(ProposedRoleCheck::class)->separationOfDuties($proposed, $acknowledgeSeparationOfDuties);

        $password = $this->generate();
        $expiresAt = now()->addDays(self::VALID_DAYS);

        try {
            $membership = DB::transaction(function () use ($name, $email, $password, $expiresAt, $organization): OrganizationMembership {
                $user = new User;
                $user->forceFill([
                    'name' => trim($name),
                    'email' => $email,
                    'password' => $password,
                    'password_change_required_at' => now(),
                    'temporary_password_expires_at' => $expiresAt,
                    'created_by_organization_id' => $organization->getKey(),
                ])->save();

                $membership = new OrganizationMembership;
                $membership->forceFill([
                    'user_id' => $user->getKey(),
                    'status' => MembershipStatus::Active,
                    'joined_at' => now(),
                ])->save();

                return $membership;
            });
        } catch (QueryException $e) {
            // Someone registered that address in the meantime.
            if ($e->getCode() === '23505') {
                throw new MembershipActionDenied('An account with that email already exists. Invite them instead: they sign in with their own password.');
            }

            throw $e;
        }

        $roleNote = $proposed === null ? null : $this->assign($actor, $membership, $proposed, $acknowledged);

        // The password itself is never logged.
        $this->audit->record('member.added_with_temporary_password', $membership, [
            'email' => $email,
            'expires_at' => $expiresAt->toIso8601String(),
            'proposed_access' => $proposed,
            'role_note' => $roleNote,
        ], actor: $actor->user, organizationId: $organization->getKey());

        return ['membership' => $membership, 'password' => $password, 'expiresAt' => $expiresAt, 'roleNote' => $roleNote];
    }

    /**
     * A new temporary password for someone this organization added who has
     * not signed in and chosen their own yet (lost or expired). The previous
     * one stops working.
     *
     * @return array{password: string, expiresAt: CarbonInterface}
     *
     * @throws MembershipActionDenied
     */
    public function reissue(OrganizationMembership $actor, OrganizationMembership $membership): array
    {
        $this->assertMayAdd($actor);

        $user = $membership->user;
        if (! $this->awaitingFirstSignIn($membership)) {
            throw new MembershipActionDenied('Only people this organization added, who have not chosen their own password yet, can get a new temporary password.');
        }

        $password = $this->generate();
        $expiresAt = now()->addDays(self::VALID_DAYS);
        $user->forceFill([
            'password' => $password,
            'password_change_required_at' => now(),
            'temporary_password_expires_at' => $expiresAt,
        ])->save();

        $this->audit->record('member.temporary_password_reissued', $membership, [
            'expires_at' => $expiresAt->toIso8601String(),
        ], actor: $actor->user, organizationId: $membership->organization_id);

        return ['password' => $password, 'expiresAt' => $expiresAt];
    }

    public function awaitingFirstSignIn(OrganizationMembership $membership): bool
    {
        $user = $membership->user;

        return $membership->isActive()
            && $user->created_by_organization_id === $membership->organization_id
            && $user->mustChangePassword();
    }

    /**
     * As the administrator who added them, so delegation rules apply. A
     * refusal keeps the membership and is reported instead.
     *
     * @param  array{role_id: string, scope: array{scope_type: string, branch_id?: string|null, department_id?: string|null}}  $proposed
     */
    private function assign(OrganizationMembership $actor, OrganizationMembership $membership, array $proposed, bool $acknowledged): ?string
    {
        $role = ApplicationRole::query()->with('application')->find($proposed['role_id']);

        if ($role === null) {
            return 'The role no longer exists, so none was given.';
        }

        try {
            app(AssignRole::class)->handle($actor, $membership, $role, Scope::fromInput($proposed['scope']), acknowledgeSeparationOfDuties: $acknowledged);
        } catch (AssignmentDenied|SeparationOfDutiesWarning|InvalidArgumentException $e) {
            return 'The role was not given: '.$e->getMessage();
        }

        return null;
    }

    private function assertMayAdd(OrganizationMembership $actor): void
    {
        if (! (new WorkspaceAbilities($this->access))->manageAccess($actor)) {
            throw new MembershipActionDenied('You cannot add people to this organization.');
        }
    }

    /**
     * Twelve characters in three groups (about 70 bits), e.g. "hK7m-Qx3p-Tz9a".
     */
    private function generate(): string
    {
        $characters = '';
        $last = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < 12; $i++) {
            $characters .= self::ALPHABET[random_int(0, $last)];
        }

        return implode('-', str_split($characters, 4));
    }
}
