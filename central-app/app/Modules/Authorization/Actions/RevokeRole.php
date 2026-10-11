<?php

namespace App\Modules\Authorization\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Authorization\Models\RoleAssignment;
use App\Modules\Authorization\Support\AccessResolver;
use App\Modules\Authorization\Support\Scope;
use App\Modules\Integration\Support\IntegrationEvents;
use App\Modules\Memberships\Models\OrganizationMembership;

/**
 * Revokes an assignment. Mirrors AssignRole: a non-owner needs
 * platform.access.manage at a scope containing the assignment and may only
 * revoke roles no more sensitive than their own in that application.
 * Anyone may give up their own roles.
 */
final class RevokeRole
{
    public function __construct(
        private readonly AccessResolver $access,
        private readonly AuditLogger $audit,
        private readonly IntegrationEvents $events,
    ) {}

    /**
     * @throws AssignmentDenied
     */
    public function handle(OrganizationMembership $revoker, RoleAssignment $assignment): void
    {
        if ($assignment->revoked_at !== null) {
            return;
        }

        $ownAssignment = $assignment->membership_id === $revoker->getKey();

        if (! $ownAssignment && ! $this->access->isOwner($revoker)) {
            if (! $this->access->holds($revoker, 'platform.access.manage', Scope::of($assignment))) {
                throw new AssignmentDenied('no_access_manage', 'You cannot manage access at that scope.');
            }

            if ($assignment->role->sensitivity > $this->access->maxSensitivity($revoker, $assignment->application_id)) {
                throw new AssignmentDenied('sensitivity_exceeded', 'That role is more sensitive than any role you hold in this application.');
            }
        }

        $assignment->forceFill([
            'revoked_at' => now(),
            'revoked_by_membership_id' => $revoker->getKey(),
        ])->save();

        $this->events->accessChanged($assignment->organization_id, $assignment->application_id, $assignment->membership_id, $assignment->membership->user_id);

        $this->audit->record('role.revoked', $assignment, [
            'membership_id' => $assignment->membership_id,
            'role' => $assignment->role->key,
            'scope' => Scope::of($assignment)->attributes(),
            'revoked_by_membership_id' => $revoker->getKey(),
        ]);
    }
}
