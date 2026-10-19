<?php

namespace App\Support;

use App\Models\User;
use App\Modules\Employees\Models\AuditEntry;
use App\Modules\Employees\Models\Employee;

/**
 * The HR audit log: who looked at or changed a sensitive record, and which
 * fields. Values are never written here.
 */
class HrAudit
{
    /**
     * @param  list<string>|null  $fields
     * @param  string|null  $subjectId  the record acted on, when more precise than the employee (a document)
     */
    public function record(User $actor, string $action, ?Employee $employee = null, ?array $fields = null, ?string $subjectId = null): void
    {
        AuditEntry::query()->create([
            'actor_id' => $actor->id,
            'action' => $action,
            'employee_id' => $employee?->id,
            'subject_id' => $subjectId,
            'fields' => $fields,
        ]);
    }
}
