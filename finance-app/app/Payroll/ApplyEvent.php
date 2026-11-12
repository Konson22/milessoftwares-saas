<?php

namespace App\Payroll;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\TimesheetEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Miles\SharedModuleKit\Envelope;

/**
 * Applies an event from HR or a product, inside the inbox's transaction
 * (once per business transaction). Events never carry pay.
 *
 *   payroll.employee.joined     add the person, or refresh their work details
 *   payroll.employee.left       their last day
 *   payroll.timesheet.submitted hours for people paid by the hour (a day sent
 *                               again replaces what was sent before)
 */
class ApplyEvent
{
    public function __construct(private readonly Staff $staff) {}

    /**
     * @throws NotYetApplicable when the person has not arrived yet (the sender retries)
     * @throws UnsupportedEvent
     */
    public function handle(Envelope $event): void
    {
        match ("{$event->eventType}.v{$event->schemaVersion}") {
            'payroll.employee.joined.v1' => $this->joined($event),
            'payroll.employee.left.v1' => $this->left($event),
            'payroll.timesheet.submitted.v1' => $this->timesheet($event),
            default => throw new UnsupportedEvent("Payroll does not handle {$event->eventType} v{$event->schemaVersion}."),
        };
    }

    private function joined(Envelope $event): void
    {
        $payload = Validator::validate($event->payload, [
            'employee_reference' => ['required', 'string', 'max:64'],
            'employee_number' => ['nullable', 'string', 'max:32'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'work_email' => ['nullable', 'email', 'max:255'],
            'branch_id' => ['nullable', 'uuid'],
            'department_id' => ['nullable', 'uuid'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'started_on' => ['required', 'date_format:Y-m-d'],
        ]);

        $this->staff->sync($event->sourceApp, $payload);
    }

    private function left(Envelope $event): void
    {
        $payload = Validator::validate($event->payload, [
            'employee_reference' => ['required', 'string', 'max:64'],
            'left_on' => ['required', 'date_format:Y-m-d'],
        ]);

        $this->staff->leave($this->employee($event->sourceApp, $payload['employee_reference']), CarbonImmutable::parse($payload['left_on']));
    }

    private function timesheet(Envelope $event): void
    {
        $payload = Validator::validate($event->payload, [
            'employee_reference' => ['required', 'string', 'max:64'],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.date' => ['required', 'date_format:Y-m-d'],
            'entries.*.hours' => ['required', 'numeric', 'min:0', 'max:24'],
            'entries.*.kind' => ['required', 'in:'.implode(',', TimesheetEntry::KINDS)],
            'entries.*.branch_id' => ['nullable', 'uuid'],
        ]);

        $employee = $this->employee($event->sourceApp, $payload['employee_reference']);

        // Hours already paid stay as they were paid.
        $locked = PayrollRun::query()
            ->where('status', '!=', PayrollRun::DRAFT)
            ->get(['number', 'period_start', 'period_end']);

        foreach ($payload['entries'] as $entry) {
            $date = CarbonImmutable::parse($entry['date']);
            foreach ($locked as $run) {
                if ($date->betweenIncluded($run->period_start, $run->period_end)) {
                    throw ValidationException::withMessages(['entries' => "{$date->toDateString()} is in payroll {$run->number}, already approved."]);
                }
            }

            TimesheetEntry::query()
                ->where('employee_id', $employee->id)
                ->where('source_app', $event->sourceApp)
                ->whereDate('work_date', $date->toDateString())
                ->where('kind', $entry['kind'])
                ->delete();

            (new TimesheetEntry([
                'employee_id' => $employee->id,
                'work_date' => $date,
                'hours' => (string) $entry['hours'],
                'kind' => $entry['kind'],
                'branch_id' => $entry['branch_id'] ?? null,
                'source_app' => $event->sourceApp,
                'source_reference' => $event->sourceTransactionId,
            ]))->save();
        }
    }

    /**
     * The sender's person: one it sent us, else one with that employee number.
     */
    private function employee(string $sourceApp, string $reference): Employee
    {
        $employee = Employee::query()->where('source_app', $sourceApp)->where('source_reference', $reference)->first()
            ?? Employee::query()->where('employee_number', $reference)->first();

        if ($employee === null) {
            throw new NotYetApplicable("Payroll has nobody with reference {$reference} yet.");
        }

        return $employee;
    }
}
