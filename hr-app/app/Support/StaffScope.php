<?php

namespace App\Support;

use App\Modules\Employees\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Miles\PlatformClient\Access\CurrentAccess;

/**
 * Which staff a permission reaches for the signed-in member: an
 * organization grant (or owner) reaches everyone, a branch grant the staff
 * of that branch, a department grant the staff of that department.
 */
class StaffScope
{
    public function __construct(private readonly CurrentAccess $access) {}

    /**
     * Limits the query to the employees the permission reaches.
     *
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public function apply(Builder $query, string $permission): Builder
    {
        $access = $this->access->get();
        if ($access === null || ! $access->has($permission)) {
            return $query->whereRaw('1 = 0');
        }

        $branches = $access->branchesFor($permission);
        $departments = $access->departmentsFor($permission);
        if ($branches === null || $departments === null) {
            return $query;
        }

        if ($branches === [] && $departments === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use ($branches, $departments): void {
            $query->whereIn('branch_id', $branches)->orWhereIn('department_id', $departments);
        });
    }

    public function allows(string $permission, Employee $employee): bool
    {
        return $this->allowsAt($permission, $employee->branch_id, $employee->department_id);
    }

    /**
     * Whether the permission reaches someone working in this branch and department.
     */
    public function allowsAt(string $permission, ?string $branchId, ?string $departmentId): bool
    {
        return $this->access->get()?->allowsIn($permission, $branchId, $departmentId) === true;
    }

    /**
     * Whether the permission is held at any scope.
     */
    public function holds(string $permission): bool
    {
        return $this->access->get()?->has($permission) === true;
    }

    /**
     * Whether the permission is held organization-wide (or by the owner).
     */
    public function organizationWide(string $permission): bool
    {
        return $this->access->get()?->allowsIn($permission) === true;
    }
}
