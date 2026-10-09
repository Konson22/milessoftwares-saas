<?php

namespace App\Modules\Authorization\Support;

use App\Modules\Authorization\Enums\ScopeType;
use App\Modules\Structure\Models\Department;

/**
 * Scope containment within the current organization:
 *
 *   organization ⊇ everything
 *   branch B     ⊇ B, and every department attached to B or below one that is
 *   department D ⊇ D and its sub-departments
 *   resource R   ⊇ R only
 *
 * Departments with no branch serve the whole organization, so only the
 * organization scope contains them. Built from the tenant-scoped department
 * table once per instance; use a fresh instance per request.
 */
final class ScopeTree
{
    /**
     * @var array<string, array{branch: string|null, parent: string|null}>|null
     */
    private ?array $departments = null;

    public function contains(Scope $outer, Scope $inner): bool
    {
        return match ($outer->type) {
            ScopeType::Organization => true,
            ScopeType::Branch => match ($inner->type) {
                ScopeType::Branch => $inner->branchId === $outer->branchId,
                ScopeType::Department => in_array($outer->branchId, $this->branchesOf((string) $inner->departmentId), true),
                default => false,
            },
            ScopeType::Department => $inner->type === ScopeType::Department
                && in_array($outer->departmentId, $this->lineage((string) $inner->departmentId), true),
            ScopeType::Resource => $inner->equals($outer),
        };
    }

    public function overlaps(Scope $a, Scope $b): bool
    {
        return $this->contains($a, $b) || $this->contains($b, $a);
    }

    /**
     * The department and all its ancestors, nearest first. Guards against
     * cycles, which the schema cannot fully rule out.
     *
     * @return list<string>
     */
    private function lineage(string $departmentId): array
    {
        $departments = $this->departments();
        $lineage = [];

        for ($id = $departmentId; $id !== null && isset($departments[$id]) && ! in_array($id, $lineage, true); $id = $departments[$id]['parent']) {
            $lineage[] = $id;
        }

        return $lineage;
    }

    /**
     * Branches a department sits under, directly or through an ancestor.
     *
     * @return list<string>
     */
    private function branchesOf(string $departmentId): array
    {
        $departments = $this->departments();

        return array_values(array_filter(array_map(
            fn (string $id) => $departments[$id]['branch'],
            $this->lineage($departmentId),
        )));
    }

    /**
     * @return array<string, array{branch: string|null, parent: string|null}>
     */
    private function departments(): array
    {
        return $this->departments ??= Department::query()
            ->get(['id', 'branch_id', 'parent_department_id'])
            ->mapWithKeys(fn (Department $department) => [
                $department->id => ['branch' => $department->branch_id, 'parent' => $department->parent_department_id],
            ])
            ->all();
    }
}
