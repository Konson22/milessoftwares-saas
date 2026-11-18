<?php

namespace App\Support;

use Illuminate\Http\Request;
use Miles\PlatformClient\Access\CurrentAccess;
use Miles\PlatformClient\Api\Branch;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * The branch a member is working in. Tables, orders, bills and stock always
 * belong to one branch; a member may work in the active branches where
 * they hold at least one restaurant permission (owners and organization-wide
 * roles: every active branch). A branch page names its branch in its
 * address (branches/{branch}/...), checked here on every request.
 *
 * Department-scoped roles do not open a branch: the platform does not tell
 * products which branch a department belongs to.
 */
class Branches
{
    private const SESSION_KEY = 'restaurant.branch_id';

    public function __construct(
        private readonly PlatformApi $api,
        private readonly CurrentTenant $tenant,
        private readonly CurrentAccess $access,
    ) {}

    /**
     * @return list<Branch>
     */
    public function available(): array
    {
        $access = $this->access->get();
        if ($access === null || ! $access->allowed) {
            return [];
        }

        $active = array_values(array_filter(
            $this->api->branches($this->tenant->require()->id),
            fn (Branch $branch) => $branch->isActive(),
        ));

        if ($access->isOwner) {
            return $active;
        }

        $scoped = [];
        foreach ($access->roles as $role) {
            $type = $role['scope']['scope_type'] ?? null;

            if ($type === 'organization') {
                return $active;
            }

            if ($type === 'branch' && isset($role['scope']['branch_id'])) {
                $scoped[$role['scope']['branch_id']] = true;
            }
        }

        return array_values(array_filter($active, fn (Branch $branch) => isset($scoped[$branch->id])));
    }

    /**
     * Whether the organization has any active branch at all, regardless of
     * who may work in it: a new organization has none until one is added
     * in the workspace.
     */
    public function anyActive(): bool
    {
        foreach ($this->api->branches($this->tenant->require()->id) as $branch) {
            if ($branch->isActive()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The branch this browser last worked in, if still open to the member
     * (or their only branch). Only a hint, for old links without a branch
     * and for the branch list: a branch page always takes its branch from
     * its address, so tabs on different branches never disturb each other.
     */
    public function remembered(Request $request): ?Branch
    {
        $available = $this->available();
        if (count($available) === 1) {
            return $available[0];
        }

        $last = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;
        foreach ($available as $branch) {
            if ($branch->id === $last) {
                return $branch;
            }
        }

        return null;
    }

    /**
     * Every branch's name, closed ones too, by id.
     *
     * @return array<string, string>
     */
    public function names(): array
    {
        $names = [];
        foreach ($this->api->branches($this->tenant->require()->id) as $branch) {
            $names[$branch->id] = $branch->name;
        }

        return $names;
    }

    public function find(string $branchId): ?Branch
    {
        foreach ($this->available() as $branch) {
            if ($branch->id === $branchId) {
                return $branch;
            }
        }

        return null;
    }

    /** Notes the branch a page was opened in (see remembered()). */
    public function remember(Request $request, Branch $branch): void
    {
        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, $branch->id);
        }
    }

    /**
     * Whether the member oversees every branch together (Global Admin, or
     * the owner): restaurant.branches.overview across the organization. A
     * role for some branches never opens the all-branches view.
     */
    public function overview(): bool
    {
        $access = $this->access->get();

        return $access !== null
            && $access->has('restaurant.branches.overview')
            && $access->branchesFor('restaurant.branches.overview') === null;
    }

    /**
     * Whether the member decides who may sign in (restaurant.access.manage
     * across the organization).
     */
    public function managesAccess(): bool
    {
        $access = $this->access->get();

        return $access !== null
            && $access->has('restaurant.access.manage')
            && $access->branchesFor('restaurant.access.manage') === null;
    }
}
