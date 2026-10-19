<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Api\Branch;
use Miles\PlatformClient\Api\Department;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Api\Position;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * The organization's branches, departments and positions, as the platform
 * keeps them (Structure is their owner; HR stores only their ids).
 */
class Structure
{
    public function __construct(
        private readonly PlatformApi $api,
        private readonly CurrentTenant $tenant,
    ) {}

    /**
     * @return list<Branch>
     */
    public function branches(): array
    {
        return $this->api->branches($this->tenant->require()->id);
    }

    /**
     * @return list<Department>
     */
    public function departments(): array
    {
        return $this->api->departments($this->tenant->require()->id);
    }

    /**
     * @return list<Position>
     */
    public function positions(): array
    {
        return $this->api->positions($this->tenant->require()->id);
    }

    /**
     * Names of every branch, department and position, closed ones too, by id.
     *
     * @return array{branches: array<string, string>, departments: array<string, string>, positions: array<string, string>}
     */
    public function names(): array
    {
        $names = ['branches' => [], 'departments' => [], 'positions' => []];
        foreach ($this->branches() as $branch) {
            $names['branches'][$branch->id] = $branch->name;
        }
        foreach ($this->departments() as $department) {
            $names['departments'][$department->id] = $department->name;
        }
        foreach ($this->positions() as $position) {
            $names['positions'][$position->id] = $position->title;
        }

        return $names;
    }

    /**
     * What a form offers: the active branches, departments and positions.
     *
     * @return array{branches: list<array{id: string, name: string}>, departments: list<array{id: string, name: string, branchId: string|null}>, positions: list<array{id: string, title: string, departmentId: string|null}>}
     */
    public function options(): array
    {
        return [
            'branches' => array_values(array_map(
                fn (Branch $branch) => ['id' => $branch->id, 'name' => $branch->name],
                array_filter($this->branches(), fn (Branch $branch) => $branch->isActive()),
            )),
            'departments' => array_values(array_map(
                fn (Department $department) => ['id' => $department->id, 'name' => $department->name, 'branchId' => $department->branchId],
                array_filter($this->departments(), fn (Department $department) => $department->isActive()),
            )),
            'positions' => array_values(array_map(
                fn (Position $position) => ['id' => $position->id, 'title' => $position->title, 'departmentId' => $position->departmentId],
                array_filter($this->positions(), fn (Position $position) => $position->isActive()),
            )),
        ];
    }

    /**
     * Checks an assignment against the platform: every id must be active,
     * and a department that belongs to a branch must be in the branch
     * chosen (company-wide departments go with any branch).
     *
     * @throws ValidationException
     */
    public function ensureAssignable(?string $branchId, ?string $departmentId, ?string $positionId): void
    {
        if ($branchId !== null && ! in_array($branchId, $this->activeIds($this->branches()), true)) {
            throw ValidationException::withMessages(['branch_id' => 'Choose an open branch.']);
        }

        if ($departmentId !== null) {
            $department = null;
            foreach ($this->departments() as $candidate) {
                if ($candidate->id === $departmentId && $candidate->isActive()) {
                    $department = $candidate;
                }
            }
            if ($department === null) {
                throw ValidationException::withMessages(['department_id' => 'Choose an active department.']);
            }
            if ($department->branchId !== null && $department->branchId !== $branchId) {
                throw ValidationException::withMessages(['department_id' => "{$department->name} belongs to another branch."]);
            }
        }

        if ($positionId !== null && ! in_array($positionId, $this->activeIds($this->positions()), true)) {
            throw ValidationException::withMessages(['position_id' => 'Choose an active position.']);
        }
    }

    /**
     * @param  list<Branch|Department|Position>  $items
     * @return list<string>
     */
    private function activeIds(array $items): array
    {
        return array_values(array_map(fn ($item) => $item->id, array_filter($items, fn ($item) => $item->isActive())));
    }
}
