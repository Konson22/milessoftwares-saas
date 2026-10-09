<?php

namespace App\Modules\Authorization\Support;

use App\Modules\Applications\Models\ApplicationPermission;
use App\Modules\Authorization\Models\RoleAssignment;
use App\Modules\Memberships\Models\OrganizationMembership;
use Illuminate\Database\Eloquent\Collection;

/**
 * Answers "what may this member do, and where?" from live role assignments in
 * the current tenant. Owners are not special here; callers decide where the
 * owner exception applies.
 */
final class AccessResolver
{
    private readonly ScopeTree $tree;

    public function __construct(?ScopeTree $tree = null)
    {
        $this->tree = $tree ?? new ScopeTree;
    }

    public function tree(): ScopeTree
    {
        return $this->tree;
    }

    /**
     * @return Collection<int, RoleAssignment>
     */
    public function liveAssignments(OrganizationMembership $membership, ?string $applicationId = null): Collection
    {
        return RoleAssignment::query()
            ->live()
            ->where('membership_id', $membership->getKey())
            ->when($applicationId !== null, fn ($query) => $query->where('application_id', $applicationId))
            ->with('role.permissions:id,key')
            ->get();
    }

    /**
     * Whether the member holds the permission at a scope containing $target.
     */
    public function holds(OrganizationMembership $membership, string $permission, Scope $target): bool
    {
        return $this->liveAssignments($membership)->contains(
            fn (RoleAssignment $assignment) => $assignment->role->permissions->contains('key', $permission)
                && $this->tree->contains(Scope::of($assignment), $target),
        );
    }

    /**
     * Permission keys the member holds in an application at $target.
     *
     * @return list<string>
     */
    public function permissionsAt(OrganizationMembership $membership, string $applicationId, Scope $target): array
    {
        $keys = $this->liveAssignments($membership, $applicationId)
            ->filter(fn (RoleAssignment $assignment) => $this->tree->contains(Scope::of($assignment), $target))
            ->flatMap(fn (RoleAssignment $assignment) => $assignment->role->permissions->map(fn (ApplicationPermission $permission): string => $permission->key))
            ->unique()
            ->sort()
            ->all();

        return array_values($keys);
    }

    /**
     * The highest role sensitivity the member holds in an application, at any
     * scope; 0 when they hold no role there.
     */
    public function maxSensitivity(OrganizationMembership $membership, string $applicationId): int
    {
        return (int) $this->liveAssignments($membership, $applicationId)->max(fn (RoleAssignment $assignment) => $assignment->role->sensitivity);
    }

    public function isOwner(OrganizationMembership $membership): bool
    {
        return $membership->organization->owner_membership_id === $membership->getKey();
    }
}
