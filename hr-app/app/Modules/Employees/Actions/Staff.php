<?php

namespace App\Modules\Employees\Actions;

use App\Models\User;
use App\Modules\Employees\Enums\EmploymentType;
use App\Modules\Employees\Enums\RecordKind;
use App\Modules\Employees\Models\Contract;
use App\Modules\Employees\Models\Employee;
use App\Support\DocumentNumbers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Taking someone on, and keeping their work details. Where they work
 * (branch, department, position) changes only through Employment, so
 * their history always says when and why.
 */
class Staff
{
    public function __construct(
        private readonly DocumentNumbers $numbers,
        private readonly Employment $employment,
    ) {}

    /**
     * Hires someone: the employee, their "hired" record and first contract.
     *
     * @param  array{first_name: string, middle_name?: string|null, last_name: string, preferred_name?: string|null, work_email?: string|null, work_phone?: string|null, manager_id?: string|null, branch_id?: string|null, department_id?: string|null, position_id?: string|null, job_title?: string|null, hired_on: string}  $work
     * @param  array{contract_type: string, ends_on?: string|null, probation_ends_on?: string|null, notice_days?: int|null}  $contract
     *
     * @throws ValidationException
     */
    public function hire(array $work, array $contract, User $by): Employee
    {
        $hiredOn = CarbonImmutable::parse($work['hired_on'])->startOfDay();
        $type = EmploymentType::from($contract['contract_type']);
        $this->employment->ensureContractDates($type, $hiredOn, $contract['ends_on'] ?? null, $contract['probation_ends_on'] ?? null);

        return DB::transaction(function () use ($work, $contract, $type, $hiredOn, $by) {
            $employee = new Employee([
                'employee_number' => $this->numbers->next('employee', 'E'),
                ...$this->names($work),
                'branch_id' => $work['branch_id'] ?? null,
                'department_id' => $work['department_id'] ?? null,
                'position_id' => $work['position_id'] ?? null,
                'job_title' => $work['job_title'] ?? null,
                'employment_type' => $type,
                'status' => Employee::ACTIVE,
                'hired_on' => $hiredOn,
                'created_by' => $by->id,
            ]);
            $employee->save();
            $this->setManager($employee, $work['manager_id'] ?? null);

            $employee->records()->create([
                'kind' => RecordKind::Hired,
                'effective_on' => $hiredOn,
                'branch_id' => $employee->branch_id,
                'department_id' => $employee->department_id,
                'position_id' => $employee->position_id,
                'job_title' => $employee->job_title,
                'recorded_by' => $by->id,
            ]);

            $employee->contracts()->create([
                'contract_type' => $type,
                'starts_on' => $hiredOn,
                'ends_on' => $contract['ends_on'] ?? null,
                'probation_ends_on' => $contract['probation_ends_on'] ?? null,
                'notice_days' => $contract['notice_days'] ?? null,
                'status' => Contract::CURRENT,
                'recorded_by' => $by->id,
            ]);

            return $employee;
        });
    }

    /**
     * Names, work contacts and manager: what can be corrected without an
     * employment record.
     *
     * @param  array{first_name: string, middle_name?: string|null, last_name: string, preferred_name?: string|null, work_email?: string|null, work_phone?: string|null, manager_id?: string|null}  $work
     *
     * @throws ValidationException
     */
    public function updateWorkDetails(Employee $employee, array $work): Employee
    {
        return DB::transaction(function () use ($employee, $work) {
            $employee->fill($this->names($work));
            $this->setManager($employee, $work['manager_id'] ?? null);

            return $employee;
        });
    }

    /**
     * @param  array<string, mixed>  $work
     * @return array<string, string|null>
     */
    private function names(array $work): array
    {
        $blank = fn (string $key) => isset($work[$key]) && trim((string) $work[$key]) !== '' ? trim((string) $work[$key]) : null;

        return [
            'first_name' => trim((string) $work['first_name']),
            'middle_name' => $blank('middle_name'),
            'last_name' => trim((string) $work['last_name']),
            'preferred_name' => $blank('preferred_name'),
            'work_email' => $blank('work_email') === null ? null : strtolower((string) $blank('work_email')),
            'work_phone' => $blank('work_phone'),
        ];
    }

    /**
     * Sets the manager and saves: an active colleague, never themselves or
     * anyone who (through others) reports to them.
     *
     * @throws ValidationException
     */
    private function setManager(Employee $employee, ?string $managerId): void
    {
        if ($managerId !== null) {
            $manager = Employee::query()->whereKey($managerId)->where('status', Employee::ACTIVE)->first()
                ?? throw ValidationException::withMessages(['manager_id' => 'Choose a current member of staff.']);

            // Walk up from the new manager: reaching this employee is a loop.
            $seen = [];
            for ($above = $manager; $above !== null; $above = $above->manager_id === null ? null : Employee::query()->find($above->manager_id)) {
                if ($above->id === $employee->id) {
                    throw ValidationException::withMessages(['manager_id' => $manager->id === $employee->id
                        ? 'Someone cannot be their own manager.'
                        : "{$manager->displayName()} reports to {$employee->displayName()}."]);
                }
                if (isset($seen[$above->id])) {
                    break;
                }
                $seen[$above->id] = true;
            }
        }

        $employee->manager_id = $managerId;
        $employee->save();
    }
}
