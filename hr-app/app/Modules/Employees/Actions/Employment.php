<?php

namespace App\Modules\Employees\Actions;

use App\Models\User;
use App\Modules\Employees\Enums\EmploymentType;
use App\Modules\Employees\Enums\LeavingReason;
use App\Modules\Employees\Enums\RecordKind;
use App\Modules\Employees\Models\Contract;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmploymentRecord;
use App\Modules\Leave\Actions\LeaveRequests;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes in someone's employment, each written as a record that is never
 * changed: moving them (transfer, promotion, change of job), new contracts,
 * and leaving. The employee always shows the latest.
 */
class Employment
{
    public function __construct(private readonly LeaveRequests $leave) {}

    /**
     * Moves someone to another branch, department or position from a date.
     *
     * @throws ValidationException
     */
    public function move(Employee $employee, RecordKind $kind, string $effectiveOn, ?string $branchId, ?string $departmentId, ?string $positionId, ?string $jobTitle, ?string $note, User $by): EmploymentRecord
    {
        if (! in_array($kind, RecordKind::moves(), true)) {
            throw ValidationException::withMessages(['kind' => 'Choose transfer, promotion or change of job.']);
        }

        return DB::transaction(function () use ($employee, $kind, $effectiveOn, $branchId, $departmentId, $positionId, $jobTitle, $note, $by) {
            $locked = $this->lockActive($employee);
            $date = CarbonImmutable::parse($effectiveOn)->startOfDay();
            $this->ensureNotBeforeLatest($locked, $date, 'effective_on');

            if ($locked->branch_id === $branchId && $locked->department_id === $departmentId
                && $locked->position_id === $positionId && $locked->job_title === $jobTitle) {
                throw ValidationException::withMessages(['branch_id' => 'Nothing changes: choose where they move to.']);
            }

            $record = $locked->records()->create([
                'kind' => $kind,
                'effective_on' => $date,
                'branch_id' => $branchId,
                'department_id' => $departmentId,
                'position_id' => $positionId,
                'job_title' => $jobTitle,
                'note' => $note,
                'recorded_by' => $by->id,
            ]);

            $locked->forceFill([
                'branch_id' => $branchId,
                'department_id' => $departmentId,
                'position_id' => $positionId,
                'job_title' => $jobTitle,
            ])->save();
            $employee->setRawAttributes($locked->getAttributes(), true);

            return $record;
        });
    }

    /**
     * A new contract from a date: it becomes current and the previous one
     * ends the day before.
     *
     * @throws ValidationException
     */
    public function newContract(Employee $employee, EmploymentType $type, string $startsOn, ?string $endsOn, ?string $probationEndsOn, ?int $noticeDays, ?string $note, User $by): Contract
    {
        $start = CarbonImmutable::parse($startsOn)->startOfDay();
        $this->ensureContractDates($type, $start, $endsOn, $probationEndsOn);

        return DB::transaction(function () use ($employee, $type, $start, $endsOn, $probationEndsOn, $noticeDays, $note, $by) {
            $locked = $this->lockActive($employee);

            $current = $locked->contracts()->where('status', Contract::CURRENT)->lockForUpdate()->first();
            if ($current !== null) {
                if ($start->lte($current->starts_on)) {
                    throw ValidationException::withMessages(['starts_on' => "The new contract must start after the current one ({$current->starts_on->toDateString()})."]);
                }
                $current->forceFill(['status' => Contract::ENDED, 'ended_on' => $start->subDay()])->save();
            }

            $contract = $locked->contracts()->create([
                'contract_type' => $type,
                'starts_on' => $start,
                'ends_on' => $endsOn,
                'probation_ends_on' => $probationEndsOn,
                'notice_days' => $noticeDays,
                'note' => $note,
                'status' => Contract::CURRENT,
                'recorded_by' => $by->id,
            ]);

            $locked->forceFill(['employment_type' => $type])->save();
            $employee->setRawAttributes($locked->getAttributes(), true);

            return $contract;
        });
    }

    /**
     * Someone stops working here: their last day, why, and their contract
     * ends with it. They keep their record (and history) as a former employee.
     *
     * @throws ValidationException
     */
    public function leave(Employee $employee, string $leftOn, LeavingReason $reason, ?string $note, User $by): EmploymentRecord
    {
        return DB::transaction(function () use ($employee, $leftOn, $reason, $note, $by) {
            $locked = $this->lockActive($employee);
            $date = CarbonImmutable::parse($leftOn)->startOfDay();
            $this->ensureNotBeforeLatest($locked, $date, 'left_on');

            $record = $locked->records()->create([
                'kind' => RecordKind::Left,
                'effective_on' => $date,
                'branch_id' => $locked->branch_id,
                'department_id' => $locked->department_id,
                'position_id' => $locked->position_id,
                'job_title' => $locked->job_title,
                'note' => trim($reason->label().($note === null || $note === '' ? '' : ": {$note}")),
                'recorded_by' => $by->id,
            ]);

            $locked->contracts()->where('status', Contract::CURRENT)->update(['status' => Contract::ENDED, 'ended_on' => $date->toDateString()]);

            $locked->forceFill([
                'status' => Employee::LEFT,
                'left_on' => $date,
                'leaving_reason' => $reason,
                'manager_id' => null,
            ])->save();
            // Nobody keeps reporting to someone who has gone.
            Employee::query()->where('manager_id', $locked->id)->update(['manager_id' => null]);
            $this->leave->cancelOnLeaving($locked, $date, $by);
            $employee->setRawAttributes($locked->getAttributes(), true);

            return $record;
        });
    }

    /**
     * @throws ValidationException
     */
    public function ensureContractDates(EmploymentType $type, CarbonImmutable $startsOn, ?string $endsOn, ?string $probationEndsOn): void
    {
        if ($type->hasEndDate() && $endsOn === null) {
            throw ValidationException::withMessages(['ends_on' => "A {$type->label()} contract needs an end date."]);
        }
        if ($endsOn !== null && CarbonImmutable::parse($endsOn)->lt($startsOn)) {
            throw ValidationException::withMessages(['ends_on' => 'The contract cannot end before it starts.']);
        }
        if ($probationEndsOn !== null && CarbonImmutable::parse($probationEndsOn)->lt($startsOn)) {
            throw ValidationException::withMessages(['probation_ends_on' => 'Probation cannot end before the contract starts.']);
        }
        if ($probationEndsOn !== null && $endsOn !== null && CarbonImmutable::parse($probationEndsOn)->gt(CarbonImmutable::parse($endsOn))) {
            throw ValidationException::withMessages(['probation_ends_on' => 'Probation cannot end after the contract.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private function lockActive(Employee $employee): Employee
    {
        $locked = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
        if (! $locked->isActive()) {
            throw ValidationException::withMessages(['employee' => "{$locked->displayName()} has left."]);
        }

        return $locked;
    }

    /**
     * History reads in order: nothing can take effect before the latest record.
     *
     * @throws ValidationException
     */
    private function ensureNotBeforeLatest(Employee $employee, CarbonImmutable $date, string $field): void
    {
        $latest = $employee->records()->max('effective_on');
        if ($latest !== null && $date->lt(CarbonImmutable::parse((string) $latest)->startOfDay())) {
            throw ValidationException::withMessages([$field => 'Cannot be before the latest change ('.CarbonImmutable::parse((string) $latest)->toDateString().').']);
        }
    }
}
