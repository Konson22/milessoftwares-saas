<?php

namespace App\Modules\Employees\Actions;

use App\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\PersonalDetails;
use App\Support\HrAudit;
use Illuminate\Support\Facades\DB;

/**
 * Reading and changing someone's personal details. Both are written to the
 * HR audit log with the field names involved, never the values.
 */
class ManagePersonalDetails
{
    public function __construct(private readonly HrAudit $audit) {}

    /**
     * The details to show, recording that they were seen.
     *
     * @return array<string, string|null>
     */
    public function view(Employee $employee, User $by): array
    {
        $details = $employee->personalDetails;
        $values = $details?->values() ?? array_fill_keys(array_keys(PersonalDetails::FIELDS), null);

        $this->audit->record($by, 'personal.viewed', $employee, array_keys(array_filter($values, fn (?string $value) => $value !== null)));

        return $values;
    }

    /**
     * Replaces the details with these (empty fields are cleared).
     *
     * @param  array<string, string|null>  $input
     */
    public function update(Employee $employee, array $input, User $by): void
    {
        DB::transaction(function () use ($employee, $input, $by) {
            $details = PersonalDetails::query()->whereKey($employee->id)->lockForUpdate()->first();
            $before = $details?->values() ?? array_fill_keys(array_keys(PersonalDetails::FIELDS), null);

            $after = [];
            foreach (array_keys(PersonalDetails::FIELDS) as $field) {
                $value = isset($input[$field]) ? trim((string) $input[$field]) : '';
                $after[$field] = $value === '' ? null : $value;
            }

            $changed = array_keys(array_filter($after, fn (?string $value, string $field) => $value !== $before[$field], ARRAY_FILTER_USE_BOTH));
            if ($changed === []) {
                return;
            }

            $details ??= new PersonalDetails(['employee_id' => $employee->id]);
            $details->fill([
                'details' => array_filter($after, fn (?string $value) => $value !== null),
                'updated_by' => $by->id,
            ])->save();

            $this->audit->record($by, 'personal.updated', $employee, $changed);
        });
    }
}
