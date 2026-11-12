<?php

namespace App\Payroll;

use App\Finance\DocumentNumbers;
use App\Models\Employee;
use App\Models\EmployeePay;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payroll's register of people it pays. A business without HR adds them
 * here; with HR (or a product that keeps staff), they arrive and stay in
 * step through events (sync, leave) and only their pay is entered here.
 */
class Staff
{
    public function __construct(private readonly DocumentNumbers $numbers) {}

    /**
     * @param  array<string, mixed>  $details
     */
    public function add(array $details, ?User $by): Employee
    {
        return DB::transaction(function () use ($details, $by) {
            $employee = new Employee([
                ...$this->workDetails($details),
                'employee_number' => $details['employee_number'] ?? $this->numbers->next('employee', 'P'),
                'pay_basis' => $details['pay_basis'] ?? Employee::MONTHLY,
                'status' => Employee::ACTIVE,
                'hired_on' => $details['hired_on'],
                'source_app' => $details['source_app'] ?? null,
                'source_reference' => $details['source_reference'] ?? null,
                'created_by' => $by?->id,
            ]);
            $employee->save();

            return $employee;
        });
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function update(Employee $employee, array $details): void
    {
        $employee->fill($this->workDetails($details));
        if (isset($details['pay_basis'])) {
            $employee->pay_basis = (string) $details['pay_basis'];
        }
        if (isset($details['hired_on']) && ! $employee->isLinked()) {
            $employee->hired_on = CarbonImmutable::parse((string) $details['hired_on']);
        }
        $employee->save();
    }

    /**
     * From another application (payroll.employee.joined): created the first
     * time, work details refreshed after that. Pay is never in the event.
     *
     * @param  array<string, mixed>  $payload
     */
    public function sync(string $sourceApp, array $payload): Employee
    {
        $reference = (string) $payload['employee_reference'];
        $details = [
            'first_name' => $payload['first_name'],
            'last_name' => $payload['last_name'],
            'work_email' => $payload['work_email'] ?? null,
            'branch_id' => $payload['branch_id'] ?? null,
            'department_id' => $payload['department_id'] ?? null,
            'job_title' => $payload['job_title'] ?? null,
        ];

        $employee = Employee::query()->where('source_app', $sourceApp)->where('source_reference', $reference)->first();
        if ($employee === null) {
            $number = isset($payload['employee_number']) && ! Employee::query()->where('employee_number', $payload['employee_number'])->exists()
                ? (string) $payload['employee_number']
                : null;

            return $this->add([...$details, 'employee_number' => $number, 'hired_on' => $payload['started_on'], 'source_app' => $sourceApp, 'source_reference' => $reference], null);
        }

        $employee->fill($this->workDetails($details));
        $employee->hired_on = CarbonImmutable::parse((string) $payload['started_on']);
        // Back again: a rehire reopens the record.
        if (! $employee->isActive() && $employee->left_on !== null && $employee->left_on->lt($employee->hired_on)) {
            $employee->status = Employee::ACTIVE;
            $employee->left_on = null;
        }
        $employee->save();

        return $employee;
    }

    public function leave(Employee $employee, CarbonImmutable $lastDay): void
    {
        if ($lastDay->lt($employee->hired_on)) {
            throw ValidationException::withMessages(['left_on' => 'The last day cannot be before they started.']);
        }

        $employee->forceFill(['status' => Employee::LEFT, 'left_on' => $lastDay])->save();
    }

    public function setPay(Employee $employee, CarbonImmutable $from, int $amountMinor, ?string $note, User $by): EmployeePay
    {
        if ($amountMinor < 0) {
            throw ValidationException::withMessages(['amount' => 'Pay cannot be negative.']);
        }
        if ($employee->pay()->whereDate('effective_from', $from->toDateString())->exists()) {
            throw ValidationException::withMessages(['effective_from' => 'Pay already changes on that date: choose another date.']);
        }

        return $employee->pay()->create([
            'effective_from' => $from,
            'amount_minor' => $amountMinor,
            'note' => $note,
            'created_by' => $by->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function workDetails(array $details): array
    {
        return array_intersect_key($details, array_flip(['first_name', 'last_name', 'work_email', 'branch_id', 'department_id', 'job_title']));
    }
}
