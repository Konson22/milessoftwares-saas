<?php

namespace Miles\PlatformClient\Api;

/**
 * What a member may do in this application
 * (GET /api/v1/organizations/{id}/members/{sub}/effective-access).
 *
 * Gate every request on allowed(). Then check permissions, and for records
 * that belong to a branch or department, the scope of the role granting the
 * permission (allowsIn()). Organization owners hold every permission at
 * organization scope.
 */
final readonly class EffectiveAccess
{
    /**
     * @param  list<array{role: string, scope: array<string, string>, permissions: list<string>, expires_at: string|null}>  $roles
     * @param  list<array{key: string, limit: int|null}>  $features
     */
    public function __construct(
        public bool $allowed,
        public string $organizationId,
        public string $subject,
        public bool $isOwner,
        public bool $entitled,
        public array $roles,
        public array $features,
        public int $maxAge,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $roles = [];
        foreach (is_array($data['roles'] ?? null) ? $data['roles'] : [] as $role) {
            if (! is_array($role) || ! is_string($role['role'] ?? null)) {
                continue;
            }
            $roles[] = [
                'role' => $role['role'],
                'scope' => array_map('strval', array_filter(is_array($role['scope'] ?? null) ? $role['scope'] : [], 'is_scalar')),
                'permissions' => array_values(array_filter(is_array($role['permissions'] ?? null) ? $role['permissions'] : [], 'is_string')),
                'expires_at' => is_string($role['expires_at'] ?? null) ? $role['expires_at'] : null,
            ];
        }

        $features = [];
        $entitlement = is_array($data['entitlement'] ?? null) ? $data['entitlement'] : [];
        foreach (is_array($entitlement['features'] ?? null) ? $entitlement['features'] : [] as $feature) {
            if (is_array($feature) && is_string($feature['key'] ?? null)) {
                $features[] = ['key' => $feature['key'], 'limit' => isset($feature['limit']) ? (int) $feature['limit'] : null];
            }
        }

        $organization = is_array($data['organization'] ?? null) ? $data['organization'] : [];
        $membership = is_array($data['membership'] ?? null) ? $data['membership'] : [];

        return new self(
            allowed: ($data['allowed'] ?? false) === true,
            organizationId: (string) ($organization['id'] ?? ''),
            subject: (string) ($data['subject'] ?? ''),
            isOwner: ($membership['is_owner'] ?? false) === true,
            entitled: ($entitlement['active'] ?? false) === true,
            roles: $roles,
            features: $features,
            maxAge: max(0, (int) ($data['max_age'] ?? 0)),
        );
    }

    /**
     * Whether the member holds the permission at any scope.
     */
    public function has(string $permission): bool
    {
        return $this->allowed && $this->scopesFor($permission) !== [];
    }

    /**
     * Whether the permission applies to a record in this branch/department:
     * an organization-scoped grant covers everything, a branch grant covers
     * that branch, a department grant that department.
     */
    public function allowsIn(string $permission, ?string $branchId = null, ?string $departmentId = null): bool
    {
        if (! $this->allowed) {
            return false;
        }

        foreach ($this->scopesFor($permission) as $scope) {
            $matches = match ($scope['scope_type'] ?? null) {
                'organization' => true,
                'branch' => $branchId !== null && ($scope['branch_id'] ?? null) === $branchId,
                'department' => $departmentId !== null && ($scope['department_id'] ?? null) === $departmentId,
                default => false,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * Branch ids where the permission is granted; null means every branch
     * (organization scope or owner).
     *
     * @return list<string>|null
     */
    public function branchesFor(string $permission): ?array
    {
        $branches = [];

        foreach ($this->scopesFor($permission) as $scope) {
            if (($scope['scope_type'] ?? null) === 'organization') {
                return null;
            }
            if (($scope['scope_type'] ?? null) === 'branch' && isset($scope['branch_id'])) {
                $branches[] = $scope['branch_id'];
            }
        }

        return array_values(array_unique($branches));
    }

    /**
     * Department ids where the permission is granted by a department-scoped
     * role; null means every department (organization scope or owner).
     * Branch grants are not included: see branchesFor().
     *
     * @return list<string>|null
     */
    public function departmentsFor(string $permission): ?array
    {
        $departments = [];

        foreach ($this->scopesFor($permission) as $scope) {
            if (($scope['scope_type'] ?? null) === 'organization') {
                return null;
            }
            if (($scope['scope_type'] ?? null) === 'department' && isset($scope['department_id'])) {
                $departments[] = $scope['department_id'];
            }
        }

        return array_values(array_unique($departments));
    }

    /**
     * @return list<array<string, string>>
     */
    public function scopesFor(string $permission): array
    {
        if ($this->isOwner) {
            return [['scope_type' => 'organization']];
        }

        $scopes = [];
        foreach ($this->roles as $role) {
            if (in_array($permission, $role['permissions'], true)) {
                $scopes[] = $role['scope'];
            }
        }

        return $scopes;
    }

    /**
     * @return list<string>
     */
    public function roleKeys(): array
    {
        return array_values(array_unique(array_column($this->roles, 'role')));
    }

    public function feature(string $key): ?int
    {
        foreach ($this->features as $feature) {
            if ($feature['key'] === $key) {
                return $feature['limit'] ?? PHP_INT_MAX;
            }
        }

        return null;
    }
}
