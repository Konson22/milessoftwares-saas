<?php

namespace App\Modules\Leave\Http;

use App\Models\User;
use App\Modules\Leave\Actions\LeaveRequests;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Models\LeaveRequest;
use App\Support\StaffScope;
use Carbon\CarbonImmutable;

/**
 * A leave request as the pages show it, with what the member may do to it.
 * Expects employee, type, requester, decider and canceller loaded.
 */
class LeaveRequestPresenter
{
    public function __construct(private readonly StaffScope $scope) {}

    /**
     * @return array<string, mixed>
     */
    public function present(LeaveRequest $leave, User $user, CarbonImmutable $today): array
    {
        $pending = $leave->status === LeaveStatus::Pending;
        $mayApprove = $pending && $this->scope->allows('hr.leave.approve', $leave->employee);
        $conflict = $mayApprove ? LeaveRequests::conflict($leave, $user) : null;

        return [
            'id' => $leave->id,
            'employeeId' => $leave->employee_id,
            'name' => $leave->employee->displayName(),
            'number' => $leave->employee->employee_number,
            'type' => $leave->type->name,
            'paid' => $leave->type->paid,
            'startsOn' => $leave->starts_on->toDateString(),
            'endsOn' => $leave->ends_on->toDateString(),
            'days' => $leave->days,
            'reason' => $leave->reason,
            'status' => $leave->status->value,
            'statusLabel' => $leave->status->label(),
            'requestedBy' => $leave->requester->name,
            'requestedAt' => $leave->created_at->toIso8601String(),
            'decidedBy' => $leave->decider?->name,
            'decidedAt' => $leave->decided_at?->toIso8601String(),
            'decisionNote' => $leave->decision_note,
            'cancelledBy' => $leave->canceller?->name,
            'canDecide' => $mayApprove && $conflict === null,
            // Why someone who could otherwise decide may not.
            'decideBlocked' => $conflict,
            'canCancel' => $this->mayCancel($leave, $user)
                && ($pending || ($leave->status === LeaveStatus::Approved && $leave->starts_on->gt($today))),
        ];
    }

    /**
     * HR cancels; whoever recorded a request may withdraw it.
     */
    public function mayCancel(LeaveRequest $leave, User $user): bool
    {
        return $this->scope->allows('hr.leave.manage', $leave->employee)
            || $leave->requested_by === $user->id;
    }
}
