<?php

namespace App\Modules\Employees\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Employees\Actions\Employment;
use App\Modules\Employees\Enums\EmploymentType;
use App\Modules\Employees\Enums\LeavingReason;
use App\Modules\Employees\Enums\RecordKind;
use App\Modules\Employees\Models\Employee;
use App\Support\StaffScope;
use App\Support\Structure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Moving someone, new contracts and leaving (hr.employment.manage where
 * they work; for a move, also where they are going).
 */
class EmploymentController extends Controller
{
    public function __construct(
        private readonly StaffScope $scope,
        private readonly Structure $structure,
        private readonly Employment $employment,
    ) {}

    public function move(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorizeFor($employee);
        $validated = $request->validate([
            'kind' => ['required', Rule::in(array_map(fn (RecordKind $kind) => $kind->value, RecordKind::moves()))],
            'effective_on' => ['required', 'date'],
            'branch_id' => ['nullable', 'uuid'],
            'department_id' => ['nullable', 'uuid'],
            'position_id' => ['nullable', 'uuid'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $branchId = $validated['branch_id'] ?? null;
        $departmentId = $validated['department_id'] ?? null;
        $this->structure->ensureAssignable($branchId, $departmentId, $validated['position_id'] ?? null);
        abort_unless($this->scope->allowsAt('hr.employment.manage', $branchId, $departmentId), 403, 'You cannot move staff there.');

        $kind = RecordKind::from($validated['kind']);
        $this->employment->move(
            $employee,
            $kind,
            $validated['effective_on'],
            $branchId,
            $departmentId,
            $validated['position_id'] ?? null,
            $validated['job_title'] ?? null,
            $validated['note'] ?? null,
            $this->user($request),
        );

        return back()->with('success', "{$kind->label()} recorded.");
    }

    public function contract(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorizeFor($employee);
        $validated = $request->validate([
            'contract_type' => ['required', Rule::enum(EmploymentType::class)],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date'],
            'probation_ends_on' => ['nullable', 'date'],
            'notice_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $this->employment->newContract(
            $employee,
            EmploymentType::from($validated['contract_type']),
            $validated['starts_on'],
            $validated['ends_on'] ?? null,
            $validated['probation_ends_on'] ?? null,
            isset($validated['notice_days']) ? (int) $validated['notice_days'] : null,
            $validated['note'] ?? null,
            $this->user($request),
        );

        return back()->with('success', 'New contract recorded.');
    }

    public function leave(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorizeFor($employee);
        $validated = $request->validate([
            'left_on' => ['required', 'date'],
            'reason' => ['required', Rule::enum(LeavingReason::class)],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $this->employment->leave($employee, $validated['left_on'], LeavingReason::from($validated['reason']), $validated['note'] ?? null, $this->user($request));

        return back()->with('success', "{$employee->displayName()} has left.");
    }

    private function authorizeFor(Employee $employee): void
    {
        abort_unless($this->scope->allows('hr.employees.view', $employee), 404);
        abort_unless($this->scope->allows('hr.employment.manage', $employee), 403);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
