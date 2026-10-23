<?php

namespace App\Modules\Employees\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Employees\Actions\Staff;
use App\Modules\Employees\Enums\EmploymentType;
use App\Modules\Employees\Enums\LeavingReason;
use App\Modules\Employees\Enums\RecordKind;
use App\Modules\Employees\Models\Contract;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmploymentRecord;
use App\Support\StaffScope;
use App\Support\Structure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff profiles: the list, hiring, and work details. Everyone sees only
 * the staff their roles reach (StaffScope); someone out of reach is not found.
 */
class EmployeeController extends Controller
{
    public const MAX_LISTED = 500;

    public function __construct(
        private readonly StaffScope $scope,
        private readonly Structure $structure,
    ) {}

    public function index(Request $request): Response
    {
        $status = in_array($request->query('status'), [Employee::ACTIVE, Employee::LEFT, 'all'], true) ? (string) $request->query('status') : Employee::ACTIVE;
        $search = trim((string) $request->query('q', ''));
        $branchId = is_string($request->query('branch')) ? $request->query('branch') : null;
        $departmentId = is_string($request->query('department')) ? $request->query('department') : null;
        $names = $this->structure->names();

        $employees = $this->scope->apply(Employee::query(), 'hr.employees.view')
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->when($branchId !== null, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->when($departmentId !== null, fn (Builder $query) => $query->where('department_id', $departmentId))
            ->when($search !== '', function (Builder $query) use ($search) {
                $like = '%'.mb_strtolower($search).'%';
                $query->where(fn (Builder $query) => $query
                    ->whereRaw('lower(first_name) like ?', [$like])
                    ->orWhereRaw('lower(last_name) like ?', [$like])
                    ->orWhereRaw('lower(preferred_name) like ?', [$like])
                    ->orWhereRaw('lower(employee_number) like ?', [$like])
                    ->orWhereRaw('lower(work_email) like ?', [$like]));
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(self::MAX_LISTED)
            ->get();

        return Inertia::render('employees/index', [
            'employees' => $employees->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'number' => $employee->employee_number,
                'name' => $employee->displayName(),
                'jobTitle' => $this->jobTitle($employee, $names['positions']),
                'branch' => $names['branches'][$employee->branch_id] ?? null,
                'department' => $names['departments'][$employee->department_id] ?? null,
                'employmentType' => $employee->employment_type->label(),
                'status' => $employee->status,
                'hiredOn' => $employee->hired_on->toDateString(),
            ]),
            'filters' => ['q' => $search, 'status' => $status, 'branch' => $branchId, 'department' => $departmentId],
            'branches' => array_map(fn (array $branch) => ['id' => $branch['id'], 'name' => $branch['name']], $this->structure->options()['branches']),
            'departments' => array_map(fn (array $department) => ['id' => $department['id'], 'name' => $department['name']], $this->structure->options()['departments']),
            'truncated' => $employees->count() === self::MAX_LISTED,
            'canHire' => $this->scope->holds('hr.employees.manage'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('employees/create', [
            ...$this->structure->options(),
            'managers' => $this->managerOptions(null),
            'employmentTypes' => EmploymentType::options(),
        ]);
    }

    public function store(Request $request, Staff $staff): RedirectResponse
    {
        $validated = $request->validate([
            ...$this->workRules(),
            'branch_id' => ['nullable', 'uuid'],
            'department_id' => ['nullable', 'uuid'],
            'position_id' => ['nullable', 'uuid'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'hired_on' => ['required', 'date'],
            'contract_type' => ['required', Rule::enum(EmploymentType::class)],
            'ends_on' => ['nullable', 'date'],
            'probation_ends_on' => ['nullable', 'date'],
            'notice_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);

        $branchId = $validated['branch_id'] ?? null;
        $departmentId = $validated['department_id'] ?? null;
        $this->structure->ensureAssignable($branchId, $departmentId, $validated['position_id'] ?? null);
        abort_unless($this->scope->allowsAt('hr.employees.manage', $branchId, $departmentId), 403, 'You cannot add staff there.');

        $employee = $staff->hire($validated, [
            'contract_type' => $validated['contract_type'],
            'ends_on' => $validated['ends_on'] ?? null,
            'probation_ends_on' => $validated['probation_ends_on'] ?? null,
            'notice_days' => isset($validated['notice_days']) ? (int) $validated['notice_days'] : null,
        ], $this->user($request));

        return to_route('employees.show', $employee)->with('success', "{$employee->displayName()} is on the staff list as {$employee->employee_number}.");
    }

    public function show(Employee $employee): Response
    {
        $this->ensureVisible($employee);
        $names = $this->structure->names();
        $employee->load([
            'manager',
            'records' => fn ($query) => $query->with('recorder:id,name')->orderByDesc('effective_on')->orderByDesc('created_at'),
            'contracts' => fn ($query) => $query->orderByDesc('starts_on'),
        ]);
        $active = $employee->isActive();

        return Inertia::render('employees/show', [
            'employee' => [
                'id' => $employee->id,
                'number' => $employee->employee_number,
                'name' => $employee->displayName(),
                'fullName' => $employee->fullName(),
                'firstName' => $employee->first_name,
                'middleName' => $employee->middle_name,
                'lastName' => $employee->last_name,
                'preferredName' => $employee->preferred_name,
                'workEmail' => $employee->work_email,
                'workPhone' => $employee->work_phone,
                'branchId' => $employee->branch_id,
                'branch' => $names['branches'][$employee->branch_id] ?? null,
                'departmentId' => $employee->department_id,
                'department' => $names['departments'][$employee->department_id] ?? null,
                'positionId' => $employee->position_id,
                'position' => $names['positions'][$employee->position_id] ?? null,
                'jobTitle' => $employee->job_title,
                'managerId' => $employee->manager_id,
                'manager' => $employee->manager === null ? null : [
                    'id' => $employee->manager->id,
                    'name' => $employee->manager->displayName(),
                    'visible' => $this->scope->allows('hr.employees.view', $employee->manager),
                ],
                'employmentType' => $employee->employment_type->label(),
                'status' => $employee->status,
                'hiredOn' => $employee->hired_on->toDateString(),
                'leftOn' => $employee->left_on?->toDateString(),
                'leavingReason' => $employee->leaving_reason?->label(),
            ],
            'records' => $employee->records->map(fn (EmploymentRecord $record) => [
                'id' => $record->id,
                'kind' => $record->kind->value,
                'kindLabel' => $record->kind->label(),
                'effectiveOn' => $record->effective_on->toDateString(),
                'branch' => $names['branches'][$record->branch_id] ?? null,
                'department' => $names['departments'][$record->department_id] ?? null,
                'jobTitle' => $record->job_title ?? ($names['positions'][$record->position_id] ?? null),
                'note' => $record->note,
                'recordedBy' => $record->recorder->name,
            ]),
            'contracts' => $employee->contracts->map(fn (Contract $contract) => [
                'id' => $contract->id,
                'type' => $contract->contract_type->label(),
                'startsOn' => $contract->starts_on->toDateString(),
                'endsOn' => $contract->ends_on?->toDateString(),
                'probationEndsOn' => $contract->probation_ends_on?->toDateString(),
                'noticeDays' => $contract->notice_days,
                'note' => $contract->note,
                'status' => $contract->status,
                'endedOn' => $contract->ended_on?->toDateString(),
            ]),
            'can' => [
                'edit' => $active && $this->scope->allows('hr.employees.manage', $employee),
                'employment' => $active && $this->scope->allows('hr.employment.manage', $employee),
                'personal' => $this->scope->organizationWide('hr.personal.view'),
                'documents' => $this->scope->allows('hr.documents.view', $employee),
                'leave' => $this->scope->allows('hr.leave.view', $employee),
            ],
            'options' => $active ? [
                ...$this->structure->options(),
                'managers' => $this->managerOptions($employee),
                'employmentTypes' => EmploymentType::options(),
                'moveKinds' => array_map(fn (RecordKind $kind) => ['value' => $kind->value, 'label' => $kind->label()], RecordKind::moves()),
                'leavingReasons' => LeavingReason::options(),
            ] : null,
        ]);
    }

    public function update(Request $request, Employee $employee, Staff $staff): RedirectResponse
    {
        $this->ensureVisible($employee);
        abort_unless($employee->isActive() && $this->scope->allows('hr.employees.manage', $employee), 403);

        $staff->updateWorkDetails($employee, $request->validate($this->workRules()));

        return back()->with('success', 'Work details saved.');
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function workRules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'preferred_name' => ['nullable', 'string', 'max:100'],
            'work_email' => ['nullable', 'email', 'max:255'],
            'work_phone' => ['nullable', 'string', 'max:32'],
            'manager_id' => ['nullable', 'uuid'],
        ];
    }

    /**
     * Current staff the member can see, to choose a manager from (the
     * present manager stays listed even when out of reach).
     *
     * @return list<array{id: string, name: string}>
     */
    private function managerOptions(?Employee $employee): array
    {
        $managers = $this->scope->apply(Employee::query(), 'hr.employees.view')
            ->where('status', Employee::ACTIVE)
            ->when($employee !== null, fn (Builder $query) => $query->whereKeyNot($employee->id))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(self::MAX_LISTED)
            ->get();

        if ($employee?->manager !== null && ! $managers->contains('id', $employee->manager->id)) {
            $managers->prepend($employee->manager);
        }

        return array_values($managers
            ->map(fn (Employee $manager) => ['id' => $manager->id, 'name' => "{$manager->displayName()} ({$manager->employee_number})"])
            ->all());
    }

    /**
     * @param  array<string, string>  $positions
     */
    private function jobTitle(Employee $employee, array $positions): ?string
    {
        return $employee->job_title ?? ($positions[$employee->position_id] ?? null);
    }

    private function ensureVisible(Employee $employee): void
    {
        abort_unless($this->scope->allows('hr.employees.view', $employee), 404);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
