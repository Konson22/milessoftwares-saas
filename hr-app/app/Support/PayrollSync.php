<?php

namespace App\Support;

use App\Modules\Employees\Models\Employee;
use Miles\PlatformClient\Events\Outbox;
use Throwable;

/**
 * Keeps Payroll's list of people in step with HR (ADR-0010): who works
 * here, where, and when they started or left. Never pay, bank or personal
 * details: those are entered in Payroll itself.
 *
 * Called inside HR's own transaction, so the event exists exactly when the
 * change does. Nothing is recorded while this deployment has no Payroll
 * events URL (PLATFORM_PAYROLL_EVENTS_URL); the outbox skips organizations
 * without Payroll.
 */
class PayrollSync
{
    public function __construct(
        private readonly Outbox $outbox,
        private readonly Structure $structure,
    ) {}

    /**
     * Hired, or their work details changed: Payroll adds or refreshes them.
     */
    public function joined(Employee $employee): void
    {
        if (! $this->enabled()) {
            return;
        }

        $payload = [
            'employee_reference' => $employee->id,
            'employee_number' => $employee->employee_number,
            'first_name' => $employee->preferred_name ?: $employee->first_name,
            'last_name' => $employee->last_name,
            'work_email' => $employee->work_email,
            'branch_id' => $employee->branch_id,
            'department_id' => $employee->department_id,
            'job_title' => $employee->job_title ?? $this->positionTitle($employee->position_id),
            'started_on' => $employee->hired_on->toDateString(),
        ];

        // The same details again are the same event (Payroll answers duplicate).
        $this->outbox->record('payroll', 'payroll.employee.joined', 1, "employee:{$employee->id}:joined:".substr(hash('sha256', (string) json_encode($payload)), 0, 16), $payload);
    }

    public function left(Employee $employee): void
    {
        if (! $this->enabled() || $employee->left_on === null) {
            return;
        }

        $this->outbox->record('payroll', 'payroll.employee.left', 1, "employee:{$employee->id}:left:{$employee->left_on->toDateString()}", [
            'employee_reference' => $employee->id,
            'left_on' => $employee->left_on->toDateString(),
        ]);
    }

    private function enabled(): bool
    {
        $url = config('platform-client.modules.payroll');

        return is_string($url) && $url !== '';
    }

    private function positionTitle(?string $positionId): ?string
    {
        if ($positionId === null) {
            return null;
        }

        try {
            return $this->structure->names()['positions'][$positionId] ?? null;
        } catch (Throwable) {
            // A job title is nice to have; never fail a hire over it.
            return null;
        }
    }
}
