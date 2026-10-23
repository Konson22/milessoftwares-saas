<?php

namespace App\Modules\Leave\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Actions\LeaveBalances;
use App\Modules\Leave\Http\LeaveRequestPresenter;
use App\Modules\Leave\Models\LeaveEntitlement;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Support\StaffScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One person's leave for a year: what they have of each kind, what they
 * took or asked for, and changing their days (hr.leave.manage).
 */
class EmployeeLeaveController extends Controller
{
    public function __construct(
        private readonly StaffScope $scope,
        private readonly LeaveBalances $balances,
        private readonly LeaveRequestPresenter $presenter,
    ) {}

    public function show(Request $request, Employee $employee): Response
    {
        abort_unless($this->scope->allows('hr.employees.view', $employee) && $this->scope->allows('hr.leave.view', $employee), 404);
        $today = CarbonImmutable::today();
        $years = range($employee->hired_on->year, max($employee->hired_on->year, $today->year + 1));
        $year = in_array((int) $request->query('year'), $years, true) ? (int) $request->query('year') : min($today->year, max($years));
        $manage = $employee->isActive() && $this->scope->allows('hr.leave.manage', $employee);

        // Every kind in use, and archived kinds this person has days or leave of that year.
        $used = LeaveEntitlement::query()->where('employee_id', $employee->id)->where('year', $year)->pluck('leave_type_id')
            ->merge(LeaveRequest::query()->where('employee_id', $employee->id)->where('year', $year)->pluck('leave_type_id'));
        $types = LeaveType::query()
            ->where(fn ($query) => $query->whereNull('archived_at')->orWhereIn('id', $used->unique()->all()))
            ->orderBy('name')
            ->get();

        $requests = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->where('year', $year)
            ->with(['employee', 'type', 'requester:id,name', 'decider:id,name', 'canceller:id,name'])
            ->orderByDesc('starts_on')
            ->get();
        $user = $this->user($request);

        return Inertia::render('employees/leave', [
            'employee' => [
                'id' => $employee->id,
                'number' => $employee->employee_number,
                'name' => $employee->displayName(),
                'status' => $employee->status,
            ],
            'year' => $year,
            'years' => $years,
            'balances' => $types->map(fn (LeaveType $type) => [
                'typeId' => $type->id,
                'type' => $type->name,
                'paid' => $type->paid,
                'allowNegative' => $type->allow_negative,
                'archived' => $type->isArchived(),
                ...$this->balances->balance($employee, $type, $year),
            ]),
            'requests' => $requests->map(fn (LeaveRequest $leave) => $this->presenter->present($leave, $user, $today)),
            'canManage' => $manage,
            'types' => $manage ? LeaveType::options() : [],
        ]);
    }

    public function update(Request $request, Employee $employee, LeaveType $leaveType): RedirectResponse
    {
        abort_unless($this->scope->allows('hr.employees.view', $employee) && $this->scope->allows('hr.leave.view', $employee), 404);
        abort_unless($employee->isActive() && $this->scope->allows('hr.leave.manage', $employee), 403);
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:'.$employee->hired_on->year, 'max:'.(CarbonImmutable::today()->year + 1)],
            'days' => ['required', 'integer', 'min:0', 'max:365'],
        ]);

        $this->balances->setDays($employee, $leaveType, (int) $validated['year'], (int) $validated['days'], $this->user($request));

        return back()->with('success', "{$leaveType->name} for {$validated['year']} set to {$validated['days']} days.");
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
