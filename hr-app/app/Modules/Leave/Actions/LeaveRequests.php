<?php

namespace App\Modules\Leave\Actions;

use App\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Asking for leave and deciding on it. A request holds its days while it
 * waits; approving uses them, rejecting or cancelling gives them back.
 *
 * Whoever recorded a request, and the person it is for, cannot decide on
 * it: someone else must (separation of duties).
 */
class LeaveRequests
{
    public function __construct(
        private readonly LeaveCalendar $calendar,
        private readonly LeaveBalances $balances,
    ) {}

    /**
     * @throws ValidationException
     */
    public function request(Employee $employee, LeaveType $type, string $startsOn, string $endsOn, ?string $reason, User $by): LeaveRequest
    {
        $start = CarbonImmutable::parse($startsOn)->startOfDay();
        $end = CarbonImmutable::parse($endsOn)->startOfDay();

        if ($type->isArchived()) {
            throw ValidationException::withMessages(['leave_type_id' => "{$type->name} is no longer in use."]);
        }
        if ($end->lt($start)) {
            throw ValidationException::withMessages(['ends_on' => 'The leave cannot end before it starts.']);
        }
        if ($start->year !== $end->year) {
            throw ValidationException::withMessages(['ends_on' => 'Leave stays within one year: split it at the new year.']);
        }
        if ($employee->hired_on->gt($start)) {
            throw ValidationException::withMessages(['starts_on' => "{$employee->displayName()} starts on {$employee->hired_on->toDateString()}."]);
        }

        $days = $this->calendar->workingDays($start, $end);
        if ($days === 0) {
            throw ValidationException::withMessages(['starts_on' => 'Those dates are all weekends or public holidays.']);
        }

        return DB::transaction(function () use ($employee, $type, $start, $end, $days, $reason, $by) {
            $locked = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isActive()) {
                throw ValidationException::withMessages(['employee_id' => "{$locked->displayName()} has left."]);
            }

            $entitlement = $this->balances->entitlement($locked, $type, $start->year, lock: true);

            $overlapping = LeaveRequest::query()
                ->where('employee_id', $locked->id)
                ->whereIn('status', LeaveStatus::holding())
                ->whereDate('starts_on', '<=', $end->toDateString())
                ->whereDate('ends_on', '>=', $start->toDateString())
                ->exists();
            if ($overlapping) {
                throw ValidationException::withMessages(['starts_on' => "{$locked->displayName()} already has leave in those dates."]);
            }

            $available = $this->balances->summarise($entitlement, $type)['available'];
            if (! $type->allow_negative && $days > $available) {
                throw ValidationException::withMessages(['ends_on' => $this->shortfall($type, $days, $available, $start->year)]);
            }

            return LeaveRequest::query()->create([
                'employee_id' => $locked->id,
                'leave_type_id' => $type->id,
                'year' => $start->year,
                'starts_on' => $start,
                'ends_on' => $end,
                'days' => $days,
                'reason' => $reason,
                'status' => LeaveStatus::Pending,
                'requested_by' => $by->id,
            ]);
        });
    }

    /**
     * @throws ValidationException
     */
    public function approve(LeaveRequest $request, ?string $note, User $by): LeaveRequest
    {
        return DB::transaction(function () use ($request, $note, $by) {
            $locked = $this->lockPending($request);
            $this->ensureMayDecide($locked, $by);
            $type = $locked->type;

            $entitlement = $this->balances->entitlement($locked->employee, $type, $locked->year, lock: true);
            $remaining = $this->balances->summarise($entitlement, $type, exceptRequestId: $locked->id)['remaining'];
            if (! $type->allow_negative && $locked->days > $remaining) {
                throw ValidationException::withMessages(['decision' => $this->shortfall($type, $locked->days, $remaining, $locked->year)]);
            }

            return $this->decide($request, $locked, LeaveStatus::Approved, $note, $by);
        });
    }

    /**
     * @throws ValidationException
     */
    public function reject(LeaveRequest $request, ?string $note, User $by): LeaveRequest
    {
        return DB::transaction(function () use ($request, $note, $by) {
            $locked = $this->lockPending($request);
            $this->ensureMayDecide($locked, $by);

            return $this->decide($request, $locked, LeaveStatus::Rejected, $note, $by);
        });
    }

    /**
     * Withdraws a waiting request, or calls off approved leave that has not
     * started. The days become available again.
     *
     * @throws ValidationException
     */
    public function cancel(LeaveRequest $request, User $by): LeaveRequest
    {
        return DB::transaction(function () use ($request, $by) {
            $locked = LeaveRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [LeaveStatus::Pending, LeaveStatus::Approved], true)) {
                throw ValidationException::withMessages(['decision' => "This request is already {$locked->status->value}."]);
            }
            if ($locked->status === LeaveStatus::Approved && $locked->starts_on->lte(CarbonImmutable::today())) {
                throw ValidationException::withMessages(['decision' => 'This leave has started: it can no longer be cancelled.']);
            }

            $locked->forceFill([
                'status' => LeaveStatus::Cancelled,
                'cancelled_by' => $by->id,
                'cancelled_at' => now(),
            ])->save();
            $request->setRawAttributes($locked->getAttributes(), true);

            return $request;
        });
    }

    /**
     * Someone leaving takes no more leave: waiting requests, and approved
     * leave after their last day, are cancelled. Runs inside Employment::leave.
     */
    public function cancelOnLeaving(Employee $employee, CarbonImmutable $leftOn, User $by): void
    {
        LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->where(fn ($query) => $query
                ->where('status', LeaveStatus::Pending->value)
                ->orWhere(fn ($query) => $query->where('status', LeaveStatus::Approved->value)->whereDate('starts_on', '>', $leftOn->toDateString())))
            ->update([
                'status' => LeaveStatus::Cancelled->value,
                'cancelled_by' => $by->id,
                'cancelled_at' => now(),
            ]);
    }

    /**
     * Why this member may not decide on the request, or null if they may.
     * Permissions and reach are the controller's to check.
     */
    public static function conflict(LeaveRequest $request, User $user): ?string
    {
        if ($request->requested_by === $user->id) {
            return 'Someone other than the person who recorded this request must decide on it.';
        }
        if (self::isSelf($request->employee, $user)) {
            return 'You cannot decide on your own leave.';
        }

        return null;
    }

    /**
     * Whether the member is this employee: the address they sign in with
     * (verified by the platform) is the employee's work email.
     */
    public static function isSelf(Employee $employee, User $user): bool
    {
        return $employee->work_email !== null
            && mb_strtolower(trim($employee->work_email)) === mb_strtolower(trim($user->email));
    }

    /**
     * @throws ValidationException
     */
    private function ensureMayDecide(LeaveRequest $request, User $by): void
    {
        $conflict = self::conflict($request, $by);
        if ($conflict !== null) {
            throw ValidationException::withMessages(['decision' => $conflict]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function lockPending(LeaveRequest $request): LeaveRequest
    {
        $locked = LeaveRequest::query()->whereKey($request->id)->lockForUpdate()->with(['employee', 'type'])->firstOrFail();
        if ($locked->status !== LeaveStatus::Pending) {
            throw ValidationException::withMessages(['decision' => "This request is already {$locked->status->value}."]);
        }

        return $locked;
    }

    private function decide(LeaveRequest $request, LeaveRequest $locked, LeaveStatus $status, ?string $note, User $by): LeaveRequest
    {
        $locked->forceFill([
            'status' => $status,
            'decided_by' => $by->id,
            'decided_at' => now(),
            'decision_note' => $note,
        ])->save();
        $request->setRawAttributes($locked->getAttributes(), true);

        return $request;
    }

    private function shortfall(LeaveType $type, int $days, int $left, int $year): string
    {
        $plural = fn (int $n) => $n === 1 ? '1 day' : "{$n} days";
        $left = max(0, $left);

        return "This needs {$plural($days)}, but only {$plural($left)} of {$type->name} ".($left === 1 ? 'is' : 'are')." left for {$year}.";
    }
}
