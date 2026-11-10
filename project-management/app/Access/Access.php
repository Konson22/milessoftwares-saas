<?php

namespace App\Access;

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
