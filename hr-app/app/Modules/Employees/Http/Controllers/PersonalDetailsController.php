<?php

namespace App\Modules\Employees\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Employees\Actions\ManagePersonalDetails;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\PersonalDetails;
use App\Support\StaffScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Personal details on a page of their own, so opening them is a deliberate
 * act that the audit log can record. Organization-wide permissions only.
 */
class PersonalDetailsController extends Controller
{
    public function __construct(
        private readonly StaffScope $scope,
        private readonly ManagePersonalDetails $details,
    ) {}

    public function show(Request $request, Employee $employee): Response
    {
        abort_unless($this->scope->organizationWide('hr.personal.view'), 403);

        return Inertia::render('employees/personal', [
            'employee' => [
                'id' => $employee->id,
                'number' => $employee->employee_number,
                'name' => $employee->displayName(),
                'status' => $employee->status,
            ],
            'fields' => array_map(fn (string $key, string $label) => ['key' => $key, 'label' => $label], array_keys(PersonalDetails::FIELDS), PersonalDetails::FIELDS),
            'values' => $this->details->view($employee, $this->user($request)),
            'canManage' => $this->scope->organizationWide('hr.personal.manage'),
        ]);
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        abort_unless($this->scope->organizationWide('hr.personal.manage'), 403);

        $rules = array_fill_keys(array_keys(PersonalDetails::FIELDS), ['nullable', 'string', 'max:255']);
        $rules['date_of_birth'] = ['nullable', 'date', 'before:today'];
        $rules['personal_email'] = ['nullable', 'email', 'max:255'];
        $rules['home_address'] = ['nullable', 'string', 'max:500'];

        $this->details->update($employee, $request->validate($rules), $this->user($request));

        return back()->with('success', 'Personal details saved.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
