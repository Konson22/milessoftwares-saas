<?php

namespace App\Modules\Authorization\Support;

use App\Modules\Authorization\Models\RoleAssignment;
use App\Modules\Memberships\Models\OrganizationMembership;

/**
 * What a member may administer in their workspace. Used both to guard the
 * management pages and to decide which links the navigation shows.
 */
final class WorkspaceAbilities
{
    public function __construct(private readonly AccessResolver $access) {}

    public function manageStructure(OrganizationMembership $membership): bool
    {
        return $this->access->isOwner($membership)
            || $this->access->holds($membership, 'platform.structure.manage', Scope::organization());
    }

    /**
     * Access management anywhere; each change is checked at its own scope.
     */
    public function manageAccess(OrganizationMembership $membership): bool
    {
        return $this->access->isOwner($membership)
            || $this->access->liveAssignments($membership)->contains(
                fn (RoleAssignment $assignment) => $assignment->role->permissions->contains('key', 'platform.access.manage'),
            );
    }

    /**
     * Who uses the workspace itself (home, members, administration). Everyone
     * else only picks one of their applications.
     */
    public function administers(OrganizationMembership $membership): bool
    {
        return $this->manageStructure($membership) || $this->manageAccess($membership);
    }

    /**
     * @return array{manageStructure: bool, manageAccess: bool}
     */
    public function for(OrganizationMembership $membership): array
    {
        return [
            'manageStructure' => $this->manageStructure($membership),
            'manageAccess' => $this->manageAccess($membership),
        ];
    }
}
