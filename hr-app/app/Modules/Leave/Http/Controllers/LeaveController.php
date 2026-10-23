<?php

namespace App\Modules\Leave\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Actions\LeaveRequests;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Http\LeaveRequestPresenter;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Support\StaffScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Leave requests across the staff the member can see: who is away today,
 * what waits for a decision, recording a request for someone, and
 * approving, rejecting or cancelling.
 *
 * hr.leave.view where the employee works shows their leave,
 * hr.leave.manage records and cancels it, hr.leave.approve decides on it
 * (never on one's own, nor on a request one recorded).
 */
class LeaveController extends Controller
{
    public const MAX_LISTED = 200;

    public function __construct(
        private readonly StaffScope $scope,
        private readonly LeaveRequests $requests,
        private readonly LeaveRequestPresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $status = in_array($request->query('status'), [...array_map(fn (LeaveStatus $status) => $status->value, LeaveStatus::cases()), 'all'], true)
            ? (string) $request->query('status')
            : LeaveStatus::Pending->value;
        $today = CarbonImmutable::today();
        $user = $this->user($request);
        $visible = fn () => LeaveRequest::query()->whereIn('employee_id', $this->scope->apply(Employee::query(), 'hr.leave.view')->select('id'));

        $requests = $visible()
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->with(['employee', 'type', 'requester:id,name', 'decider:id,name', 'canceller:id,name'])
            ->when($status === LeaveStatus::Pending->value, fn (Builder $query) => $query->orderBy('starts_on'), fn (Builder $query) => $query->orderByDesc('starts_on'))
            ->limit(self::MAX_LISTED)
            ->get();

        $away = $visible()
            ->where('status', LeaveStatus::Approved->value)
            ->whereDate('starts_on', '<=', $today->toDateString())
            ->whereDate('ends_on', '>=', $today->toDateString())
            ->with(['employee', 'type'])
            ->orderBy('ends_on')
            ->limit(self::MAX_LISTED)
            ->get();

        $canRecord = $this->scope->holds('hr.leave.manage');

        return Inertia::render('leave/index', [
            'requests' => $requests->map(fn (LeaveRequest $leave) => $this->presenter->present($leave, $user, $today)),
            'away' => $away->map(fn (LeaveRequest $leave) => [
                'id' => $leave->id,
                'employeeId' => $leave->employee_id,
                'name' => $leave->employee->displayName(),
                'type' => $leave->type->name,
                'endsOn' => $leave->ends_on->toDateString(),
            ]),
            'filters' => ['status' => $status],
            'statuses' => LeaveStatus::options(),
            'truncated' => $requests->count() === self::MAX_LISTED,
            'canRecord' => $canRecord,
            'options' => $canRecord ? [
                'employees' => array_values($this->scope->apply(Employee::query(), 'hr.leave.manage')
                    ->where('status', Employee::ACTIVE)
                    ->orderBy('last_name')
                    ->orderBy('first_name')
                    ->get()
                    ->map(fn (Employee $employee) => ['value' => $employee->id, 'label' => "{$employee->displayName()} ({$employee->employee_number})"])
                    ->all()),
                'types' => LeaveType::options(),
            ] : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'uuid'],
            'leave_type_id' => ['required', 'uuid'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $employee = Employee::query()->whereKey($validated['employee_id'])->first();
        if ($employee === null || ! $this->scope->allows('hr.leave.view', $employee)) {
            throw ValidationException::withMessages(['employee_id' => 'Choose someone on the staff list.']);
        }
        abort_unless($this->scope->allows('hr.leave.manage', $employee), 403);

        $type = LeaveType::query()->whereKey($validated['leave_type_id'])->first();
        if ($type === null) {
            throw ValidationException::withMessages(['leave_type_id' => 'Choose a kind of leave.']);
        }

        $leave = $this->requests->request($employee, $type, $validated['starts_on'], $validated['ends_on'], $validated['reason'] ?? null, $this->user($request));

        return back()->with('success', "{$type->name} for {$employee->displayName()} recorded: {$leave->days} ".($leave->days === 1 ? 'day' : 'days').', waiting for a decision.');
    }

    public function approve(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $this->authorizeFor($leaveRequest, 'hr.leave.approve');
        $note = $request->validate(['note' => ['nullable', 'string', 'max:255']])['note'] ?? null;

        $this->requests->approve($leaveRequest, $note, $this->user($request));

        return back()->with('success', "Leave for {$leaveRequest->employee->displayName()} approved.");
    }

    public function reject(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $this->authorizeFor($leaveRequest, 'hr.leave.approve');
        $note = $request->validate(['note' => ['nullable', 'string', 'max:255']])['note'] ?? null;

        $this->requests->reject($leaveRequest, $note, $this->user($request));

        return back()->with('success', "Leave for {$leaveRequest->employee->displayName()} rejected.");
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($this->scope->allows('hr.leave.view', $leaveRequest->employee), 404);
        abort_unless($this->presenter->mayCancel($leaveRequest, $user), 403);

        $this->requests->cancel($leaveRequest, $user);

        return back()->with('success', "Leave for {$leaveRequest->employee->displayName()} cancelled.");
    }

    private function authorizeFor(LeaveRequest $leave, string $permission): void
    {
        abort_unless($this->scope->allows('hr.leave.view', $leave->employee), 404);
        abort_unless($this->scope->allows($permission, $leave->employee), 403);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
