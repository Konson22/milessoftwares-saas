<?php

namespace App\Pharmacy;

use Illuminate\Http\Request;
use Miles\PlatformClient\Access\CurrentAccess;
use Miles\PlatformClient\Api\Branch;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * The branch a member is working in. Stock, sales and receipts always
 * belong to one branch; a member may work in the active branches where
 * they hold at least one pharmacy permission (owners and organization-wide
 * roles: every active branch). The choice is kept in the session, which
 * is already bound to one workspace.
 *
 * Department-scoped roles do not open a branch: the platform does not tell
 * products which branch a department belongs to.
 */
class Branches
{
    private const SESSION_KEY = 'pharmacy.branch_id';

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
     * The branch chosen for this session, if it is still available. With
     * exactly one available branch it is chosen automatically.
     */
    public function current(Request $request): ?Branch
    {
        $available = $this->available();
        $chosen = $request->session()->get(self::SESSION_KEY);

        foreach ($available as $branch) {
            if ($branch->id === $chosen) {
                return $branch;
            }
        }

        if (count($available) === 1) {
            $this->choose($request, $available[0]);

            return $available[0];
        }

        return null;
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

    public function choose(Request $request, Branch $branch): void
    {
        $request->session()->put(self::SESSION_KEY, $branch->id);
    }
}
