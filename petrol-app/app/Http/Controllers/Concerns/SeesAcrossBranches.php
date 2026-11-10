<?php

namespace App\Http\Controllers\Concerns;

use App\Support\Branches;
use Miles\PlatformClient\Access\CurrentAccess;
use Miles\PlatformClient\Api\Branch;

/**
 * Company-wide pages (the dashboard, stations, staff, inventory): the
 * stations a member may see there, from the permissions the page needs.
 * An owner, or a permission granted company-wide, sees every station;
 * otherwise only the stations the permission is granted in. 403 when none
 * of the permissions is held anywhere.
 */
trait SeesAcrossBranches
{
    /**
     * @param  list<string>  $permissions
     * @return list<Branch>
     */
    protected function branchesWith(Branches $branches, array $permissions): array
    {
        $access = app(CurrentAccess::class)->get();
        $held = $access === null ? [] : array_values(array_filter($permissions, fn (string $permission) => $access->has($permission)));
        abort_unless($access !== null && ($access->isOwner || $held !== []), 403);

        $available = $branches->available();
        if ($access->isOwner) {
            return $available;
        }

        $granted = [];
        foreach ($held as $permission) {
            $ids = $access->branchesFor($permission);
            if ($ids === null) {
                return $available;
            }
            $granted = array_merge($granted, $ids);
        }

        return array_values(array_filter($available, fn (Branch $branch) => in_array($branch->id, $granted, true)));
    }

    /**
     * The station ids where the member holds a permission, among those given.
     *
     * @param  list<Branch>  $branches
     * @return list<string>
     */
    protected function branchIdsAllowing(array $branches, string $permission): array
    {
        $access = app(CurrentAccess::class)->get();
        if ($access === null) {
            return [];
        }

        return array_values(array_map(
            fn (Branch $branch) => $branch->id,
            array_filter($branches, fn (Branch $branch) => $access->isOwner || $access->allowsIn($permission, branchId: $branch->id)),
        ));
    }

    /**
     * @param  list<Branch>  $branches
     * @return array<string, string>
     */
    protected function namesOf(array $branches): array
    {
        $names = [];
        foreach ($branches as $branch) {
            $names[$branch->id] = $branch->name;
        }

        return $names;
    }
}
