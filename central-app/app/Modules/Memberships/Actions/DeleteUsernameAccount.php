<?php

namespace App\Modules\Memberships\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Support\SessionTracker;
use App\Modules\Memberships\Enums\MembershipStatus;
use App\Modules\Memberships\Models\OrganizationMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Deletes a username account (no email) that this organization created, for
 * someone who has left: a pump attendant, say. The member is removed first
 * (ManageMembers: same permission, roles revoked, products told). Then the
 * account is closed for good: deactivated, so no sign-in path accepts it,
 * every session and product token revoked, the password replaced, and the
 * username freed for someone new.
 *
 * The row itself stays: the membership history, the audit log and the
 * products' records (a sale's attendant) point at it, so the name is kept
 * for those. The username becomes a placeholder nobody can register (the
 * table needs an email or a username).
 */
final class DeleteUsernameAccount
{
    public function __construct(
        private readonly ManageMembers $members,
        private readonly SessionTracker $sessions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws MembershipActionDenied
     */
    public function handle(OrganizationMembership $actor, OrganizationMembership $member): void
    {
        $user = $member->user;

        if (! $user->hasUsernameOnly() || $user->created_by_organization_id !== $member->organization_id) {
            throw new MembershipActionDenied('Only username accounts this organization created can be deleted. Remove the member instead.');
        }

        $elsewhere = OrganizationMembership::acrossTenants()
            ->where('user_id', $user->getKey())
            ->whereKeyNot($member->getKey())
            ->where('status', '!=', MembershipStatus::Removed)
            ->exists();
        if ($elsewhere) {
            throw new MembershipActionDenied('This account belongs to another organization too. Remove the member instead.');
        }

        DB::transaction(function () use ($actor, $member, $user): void {
            if ($member->status !== MembershipStatus::Removed) {
                $this->members->setStatus($actor, $member, MembershipStatus::Removed);
            } else {
                // Already removed: still only someone allowed to remove members.
                $this->members->assertMayChange($actor, $member);
            }

            $username = (string) $user->username;
            $user->forceFill([
                'status' => 'deactivated',
                'username' => '~deleted-'.Str::lower(Str::random(16)),
                'password' => Str::random(64),
                'password_change_required_at' => null,
                'temporary_password_expires_at' => null,
            ])->save();

            $this->sessions->revokeAll($user, 'account_deleted');

            $tokenIds = DB::table('oauth_access_tokens')
                ->where('user_id', $user->getKey())
                ->where('revoked', false)
                ->pluck('id');
            DB::table('oauth_access_tokens')->whereIn('id', $tokenIds)->update(['revoked' => true]);
            DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);

            $this->audit->record('member.account_deleted', $member, [
                'user_id' => $user->getKey(),
                'username' => $username,
                'by_membership_id' => $actor->getKey(),
            ], actor: $actor->user, organizationId: $member->organization_id);
        });
    }
}
