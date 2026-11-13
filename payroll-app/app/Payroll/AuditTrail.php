<?php

namespace App\Payroll;

use App\Models\AuditEvent;
use App\Models\Employee;
use App\Models\PayComponent;
use App\Models\PayrollRun;
use App\Models\StatutoryRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Records who did what in Payroll. Called inside the change's transaction,
 * so an entry exists exactly when the change does.
 */
class AuditTrail
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function record(string $action, Model $subject, ?User $user, array $details = []): AuditEvent
    {
        [$type, $label] = match (true) {
            $subject instanceof PayrollRun => ['run', "{$subject->number} ({$subject->label()})"],
            $subject instanceof Employee => ['employee', "{$subject->name()} ({$subject->employee_number})"],
            $subject instanceof PayComponent => ['component', $subject->name],
            $subject instanceof StatutoryRule => ['rule', "{$subject->name} from {$subject->effective_from->toDateString()}"],
            default => throw new InvalidArgumentException('Payroll does not audit '.$subject::class),
        };

        return AuditEvent::query()->create([
            'action' => $action,
            'subject_type' => $type,
            'subject_id' => $subject->getKey(),
            'subject_label' => mb_substr($label, 0, 255),
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'details' => array_filter($details, fn ($value) => $value !== null) ?: null,
        ]);
    }
}
