<?php

namespace App\Modules\Memberships\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Authorization\Models\RoleAssignment;
use App\Modules\Authorization\Support\AccessResolver;
use App\Modules\Authorization\Support\Scope;
use App\Modules\Integration\Support\IntegrationEvents;
use App\Modules\Memberships\Enums\MembershipStatus;
use App\Modules\Memberships\Models\OrganizationMembership;
use Illuminate\Support\Facades\DB;

/**
 * Suspending, reactivating and removing members. Needs the owner or access
 * management for the whole organization. The owner cannot be changed this
 * way, and nobody acts on their own membership.
 */
final class ManageMembers
{
    public function __construct(
        private readonly AccessResolver $access,
        private readonly AuditLogger $audit,
        private readonly IntegrationEvents $events,
    ) {}

    public function setStatus(OrganizationMembership $actor, OrganizationMembership $member, MembershipStatus $status): void
    {
        $this->assertAllowed($actor, $member);

        DB::transaction(function () use ($actor, $member, $status): void {
            $member->forceFill([
                'status' => $status,
                'suspended_at' => $status === MembershipStatus::Suspended ? now() : null,
                'removed_at' => $status === MembershipStatus::Removed ? now() : null,
            ])->save();

            // A removed member keeps nothing; being invited back starts from scratch.
            if ($status === MembershipStatus::Removed) {
                RoleAssignment::query()->live()->where('membership_id', $member->getKey())
                    ->update(['revoked_at' => now(), 'revoked_by_membership_id' => $actor->getKey()]);

                DB::table('application_access_grants')
                    ->where('membership_id', $member->getKey())
                    ->where('status', 'active')
                    ->update(['status' => 'revoked', 'revoked_at' => now(), 'updated_at' => now()]);
            }
        });

        $this->events->membershipStatusChanged($member->organization_id, $member->id, $member->user_id, $status->value);

        $this->audit->record('membership.'.$status->value, $member, [
            'membership_id' => $member->getKey(),
            'by_membership_id' => $actor->getKey(),
        ]);
    }

    private function assertAllowed(OrganizationMembership $actor, OrganizationMembership $member): void
    {
        if ($actor->is($member)) {
            throw new MembershipActionDenied('You cannot change your own membership.');
        }

        if ($this->access->isOwner($member)) {
            throw new MembershipActionDenied('The owner\'s membership cannot be changed.');
        }

        $authorized = $this->access->isOwner($actor) || $this->access->holds($actor, 'platform.access.manage', Scope::organization());

        if (! $authorized) {
            throw new MembershipActionDenied('Only the owner or an organization-wide access administrator can do that.');
        }
    }
}
