<?php

namespace App\Modules\Authorization\Support;

use App\Modules\Applications\Models\Application;
use App\Modules\Applications\Models\ApplicationRole;
use App\Modules\Memberships\Models\OrganizationMembership;
use InvalidArgumentException;

/**
 * Applications that let their own managers look after their people
 * (config platform.application_people_managers: application code →
 * permission key in that application's catalog). A member holding that
 * permission may, for that application only and without
 * platform.access.manage:
 *
 *  - give and take back its roles (AssignRole / RevokeRole still apply the
 *    rest: delegable roles only, no more sensitive than their own, never a
 *    sensitive role to themselves);
 *  - invite someone, or add a username account, when every role proposed
 *    with it is one of that application's.
 *
 * Everything else about the workspace (other applications, branches,
 * removing members) stays with owners and access administrators.
 */
final class ApplicationPeopleManagers
{
    public function __construct(private readonly AccessResolver $access) {}

    /**
     * The permission that manages the application's people, or null when
     * the application leaves that to the workspace.
     */
    public function permissionFor(string $applicationId): ?string
    {
        $code = Application::query()->whereKey($applicationId)->value('code');
        $permission = is_string($code) ? (config('platform.application_people_managers', [])[$code] ?? null) : null;

        return is_string($permission) && $permission !== '' ? $permission : null;
    }

    public function manages(OrganizationMembership $membership, string $applicationId, Scope $scope): bool
    {
        $permission = $this->permissionFor($applicationId);

        return $permission !== null
            && in_array($permission, $this->access->permissionsAt($membership, $applicationId, $scope), true);
    }

    /**
     * Whether the member may propose all these roles as a people manager:
     * at least one, each of an application they manage at its scope.
     *
     * @param  list<array{role_id: string, scope: array<string, mixed>}>  $proposed
     */
    public function managesAll(OrganizationMembership $membership, array $proposed): bool
    {
        if ($proposed === []) {
            return false;
        }

        foreach ($proposed as $proposal) {
            $role = ApplicationRole::query()->find($proposal['role_id'] ?? null);

            try {
                $scope = Scope::fromInput($proposal['scope']);
            } catch (InvalidArgumentException) {
                return false;
            }

            if ($role === null || ! $this->manages($membership, $role->application_id, $scope)) {
                return false;
            }
        }

        return true;
    }
}
