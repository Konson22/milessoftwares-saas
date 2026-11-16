<?php

namespace App\Access;

use Miles\PlatformClient\Api\EffectiveAccess;

/**
 * What a member may do in the current organization: from the platform on
 * each request (App\Http\Middleware\EnterWorkspace), or the copy kept on
 * their membership for work without a request (AccessResolver). Roles and
 * permissions are given on the platform (platform/catalog.json); owners hold
 * every permission.
 */
final readonly class Access
{
    /**
     * @param  list<string>  $permissions  for an owner, every catalog permission
     * @param  list<string>  $roles  role keys
     */
    public function __construct(
        public string $organizationId,
        public string $membershipId,
        public bool $isOwner,
        public array $permissions,
        public array $roles,
    ) {}

    /**
     * The permissions the platform gives a member here: every catalog
     * permission for an owner, otherwise those of their roles. Sorted.
     *
     * @return list<string>
     */
    public static function permissionsFrom(EffectiveAccess $platform): array
    {
        $permissions = $platform->isOwner
            ? Catalog::permissions()
            : array_values(array_unique(array_merge(...array_map(fn (array $role) => $role['permissions'], $platform->roles ?: [['permissions' => []]]))));
        sort($permissions);

        return $permissions;
    }

    public function has(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /**
     * Every permission held, for showing and hiding UI.
     *
     * @return list<string>
     */
    public function all(): array
    {
        return $this->permissions;
    }
}
