<?php

namespace App\Sites\Central\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Modules\Audit\AuditLogger;
use App\Modules\Authorization\Support\AccessResolver;
use App\Modules\Structure\Models\Branch;
use App\Modules\Structure\Models\Department;
use App\Modules\Structure\Models\Position;
use App\Sites\Central\Http\Controllers\Workspace\Concerns\AuthorizesWorkspaceAdministration;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Branches, departments and positions of the current workspace. Records are
 * archived rather than deleted, because role assignments and memberships
 * refer to them.
 */
class StructureController extends Controller
{
    use AuthorizesWorkspaceAdministration;

    public function __construct(
        private readonly AccessResolver $access,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeStructure($request, $this->access);

        return Inertia::render('central/workspace/manage/structure', [
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name', 'code', 'city', 'status']),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name', 'code', 'branch_id', 'parent_department_id', 'status']),
            'positions' => Position::query()->orderBy('title')->get(['id', 'title', 'code', 'department_id', 'status']),
        ]);
    }

    public function storeBranch(Request $request): RedirectResponse
    {
        $this->authorizeStructure($request, $this->access);

        $branch = Branch::query()->create($this->validateBranch($request));

        return $this->saved('branch.created', $branch, 'Branch added.');
    }

    public function updateBranch(Request $request, Branch $branch): RedirectResponse
    {
        $this->authorizeStructure($request, $this->access);

        $branch->update($this->validateBranch($request, $branch));

        return $this->saved('branch.updated', $branch, 'Branch updated.');
    }

    public function storeDepartment(Request $request): RedirectResponse
    {
        $this->authorizeStructure($request, $this->access);

        $department = Department::query()->create($this->validateDepartment($request));

        return $this->saved('department.created', $department, 'Department added.');
    }

    public function updateDepartment(Request $request, Department $department): RedirectResponse
    {
        $this->authorizeStructure($request, $this->access);

        $department->update($this->validateDepartment($request, $department));

        return $this->saved('department.updated', $department, 'Department updated.');
    }

    public function storePosition(Request $request): RedirectResponse
    {
        $this->authorizeStructure($request, $this->access);

        $position = Position::query()->create($this->validatePosition($request));

        return $this->saved('position.created', $position, 'Position added.');
    }

    public function updatePosition(Request $request, Position $position): RedirectResponse
    {
        $this->authorizeStructure($request, $this->access);

        $position->update($this->validatePosition($request, $position));

        return $this->saved('position.updated', $position, 'Position updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateBranch(Request $request, ?Branch $branch = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32', 'alpha_dash', $this->uniqueInTenant('branches', $branch)],
            'address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:128'],
            'country_code' => ['nullable', 'string', 'size:2', 'alpha'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'archived'])],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateDepartment(Request $request, ?Department $department = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32', 'alpha_dash', $this->uniqueInTenant('departments', $department)],
            'branch_id' => ['nullable', 'uuid', $this->existsInTenant('branches')],
            'parent_department_id' => ['nullable', 'uuid', $this->existsInTenant('departments')],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'archived'])],
        ]);

        $parent = $validated['parent_department_id'] ?? null;

        if ($department !== null && $parent !== null && $this->isSelfOrDescendant($department, (string) $parent)) {
            throw ValidationException::withMessages(['parent_department_id' => 'A department cannot sit under itself or one of its sub-departments.']);
        }

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePosition(Request $request, ?Position $position = null): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32', 'alpha_dash', $this->uniqueInTenant('positions', $position)],
            'department_id' => ['nullable', 'uuid', $this->existsInTenant('departments')],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'archived'])],
        ]);
    }

    /**
     * The validator's exists rule bypasses model scopes, so the tenant is
     * applied explicitly: ids from other organizations fail validation.
     */
    private function existsInTenant(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('organization_id', $this->tenant->require()->getKey());
    }

    private function uniqueInTenant(string $table, ?Model $ignore): Unique
    {
        $rule = Rule::unique($table, 'code')->where('organization_id', $this->tenant->require()->getKey());

        return $ignore === null ? $rule : $rule->ignore($ignore->getKey());
    }

    private function isSelfOrDescendant(Department $department, string $candidateParentId): bool
    {
        $parents = Department::query()->pluck('parent_department_id', 'id')->all();

        for ($id = $candidateParentId, $seen = []; $id !== null && ! in_array($id, $seen, true); $id = $parents[$id] ?? null) {
            if ($id === $department->id) {
                return true;
            }
            $seen[] = $id;
        }

        return false;
    }

    private function saved(string $action, Model $subject, string $message): RedirectResponse
    {
        $this->audit->record($action, $subject, ['changes' => $subject->getChanges() ?: $subject->getAttributes()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __($message)]);

        return back();
    }
}
