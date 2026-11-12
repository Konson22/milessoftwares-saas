<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeComponent;
use App\Models\EmployeePay;
use App\Models\PayComponent;
use App\Payroll\Staff;
use App\Support\StaffScope;
use App\Support\Structure;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * People on the payroll. Someone HR (or a product) keeps in step shows
 * where they come from; their work details are changed there.
 */
class EmployeeController extends Controller
{
    public const MAX_LISTED = 500;

    public function __construct(
        private readonly StaffScope $scope,
        private readonly Structure $structure,
    ) {}

    public function index(Request $request): Response
    {
        $status = in_array($request->query('status'), [Employee::ACTIVE, Employee::LEFT, 'all'], true) ? (string) $request->query('status') : Employee::ACTIVE;
        $search = trim((string) $request->query('q', ''));
        $names = $this->structure->names();
        $seesPay = $this->scope->organizationWide('payroll.pay.view');
        $today = CarbonImmutable::today()->toDateString();

        $employees = $this->scope->apply(Employee::query(), 'payroll.employees.view')
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->when($search !== '', function (Builder $query) use ($search) {
                $like = '%'.mb_strtolower($search).'%';
                $query->where(fn (Builder $query) => $query
                    ->whereRaw('lower(first_name) like ?', [$like])
                    ->orWhereRaw('lower(last_name) like ?', [$like])
                    ->orWhereRaw('lower(employee_number) like ?', [$like]));
            })
            ->when($seesPay, fn (Builder $query) => $query->with(['pay' => fn ($query) => $query->whereDate('effective_from', '<=', $today)->orderByDesc('effective_from')]))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(self::MAX_LISTED)
            ->get();

        return Inertia::render('employees/index', [
            'employees' => $employees->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'number' => $employee->employee_number,
                'name' => $employee->name(),
                'jobTitle' => $employee->job_title,
                'branch' => $names['branches'][$employee->branch_id] ?? null,
                'department' => $names['departments'][$employee->department_id] ?? null,
                'payBasis' => $employee->pay_basis,
                'status' => $employee->status,
                'source' => $employee->source_app,
                'payMinor' => $seesPay ? $employee->pay->first()?->amount_minor : null,
            ]),
            'filters' => ['q' => $search, 'status' => $status],
            'truncated' => $employees->count() === self::MAX_LISTED,
            'canAdd' => $this->scope->holds('payroll.employees.manage'),
            'seesPay' => $seesPay,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('employees/create', $this->structure->options());
    }

    public function store(Request $request, Staff $staff): RedirectResponse
    {
        $validated = $request->validate([
            ...$this->workRules(),
            'pay_basis' => ['required', Rule::in([Employee::MONTHLY, Employee::HOURLY])],
            'hired_on' => ['required', 'date'],
        ]);

        $this->structure->ensureAssignable($validated['branch_id'] ?? null, $validated['department_id'] ?? null, null);
        abort_unless($this->scope->allowsAt('payroll.employees.manage', $validated['branch_id'] ?? null, $validated['department_id'] ?? null), 403, 'You cannot add people there.');

        $employee = $staff->add($validated, $this->user($request));

        return to_route('employees.show', $employee)->with('success', "{$employee->name()} is on the payroll as {$employee->employee_number}.");
    }

    public function show(Employee $employee): Response
    {
        abort_unless($this->scope->allows('payroll.employees.view', $employee), 404);
        $names = $this->structure->names();
        $seesPay = $this->scope->organizationWide('payroll.pay.view');
        $managesPay = $employee->isActive() && $this->scope->organizationWide('payroll.pay.manage');

        return Inertia::render('employees/show', [
            'employee' => [
                'id' => $employee->id,
                'number' => $employee->employee_number,
                'name' => $employee->name(),
                'firstName' => $employee->first_name,
                'lastName' => $employee->last_name,
                'workEmail' => $employee->work_email,
                'jobTitle' => $employee->job_title,
                'branchId' => $employee->branch_id,
                'branch' => $names['branches'][$employee->branch_id] ?? null,
                'departmentId' => $employee->department_id,
                'department' => $names['departments'][$employee->department_id] ?? null,
                'payBasis' => $employee->pay_basis,
                'status' => $employee->status,
                'hiredOn' => $employee->hired_on->toDateString(),
                'leftOn' => $employee->left_on?->toDateString(),
                'source' => $employee->source_app,
            ],
            'pay' => $seesPay ? $employee->pay()->with('creator:id,name')->orderByDesc('effective_from')->get()->map(fn (EmployeePay $pay) => [
                'id' => $pay->id,
                'effectiveFrom' => $pay->effective_from->toDateString(),
                'amountMinor' => $pay->amount_minor,
                'note' => $pay->note,
                'by' => $pay->creator->name,
            ]) : null,
            'components' => $seesPay ? $employee->components()->with('component')->orderByDesc('starts_on')->get()->map(fn (EmployeeComponent $assigned) => [
                'id' => $assigned->id,
                'name' => $assigned->component->name,
                'kind' => $assigned->component->kind,
                'calculation' => $assigned->component->calculation,
                'amountMinor' => $assigned->amount_minor,
                'rateBp' => $assigned->rate_bp,
                'startsOn' => $assigned->starts_on->toDateString(),
                'endsOn' => $assigned->ends_on?->toDateString(),
            ]) : null,
            'paymentDetails' => $seesPay ? ($employee->payment_details ?? []) : null,
            'componentOptions' => $managesPay ? PayComponent::query()->where('active', true)->orderBy('kind')->orderBy('name')->get()->map(fn (PayComponent $component) => [
                'id' => $component->id,
                'name' => $component->name,
                'kind' => $component->kind,
                'calculation' => $component->calculation,
            ]) : [],
            'options' => $this->structure->options(),
            'can' => [
                'edit' => $employee->isActive() && $this->scope->allows('payroll.employees.manage', $employee),
                'managePay' => $managesPay,
            ],
        ]);
    }

    public function update(Request $request, Employee $employee, Staff $staff): RedirectResponse
    {
        abort_unless($this->scope->allows('payroll.employees.view', $employee), 404);
        abort_unless($employee->isActive() && $this->scope->allows('payroll.employees.manage', $employee), 403);

        $rules = ['pay_basis' => ['required', Rule::in([Employee::MONTHLY, Employee::HOURLY])]];
        if (! $employee->isLinked()) {
            $rules = [...$rules, ...$this->workRules(), 'hired_on' => ['required', 'date']];
        }
        $validated = $request->validate($rules);

        if (! $employee->isLinked()) {
            $this->structure->ensureAssignable($validated['branch_id'] ?? null, $validated['department_id'] ?? null, null);
            abort_unless($this->scope->allowsAt('payroll.employees.manage', $validated['branch_id'] ?? null, $validated['department_id'] ?? null), 403);
        }

        $staff->update($employee, $validated);

        return back()->with('success', 'Saved.');
    }

    public function leave(Request $request, Employee $employee, Staff $staff): RedirectResponse
    {
        abort_unless($this->scope->allows('payroll.employees.view', $employee), 404);
        abort_unless($employee->isActive() && ! $employee->isLinked() && $this->scope->allows('payroll.employees.manage', $employee), 403);

        $validated = $request->validate(['left_on' => ['required', 'date']]);
        $staff->leave($employee, CarbonImmutable::parse($validated['left_on']));

        return back()->with('success', "{$employee->name()} is paid up to {$validated['left_on']}.");
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function workRules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'work_email' => ['nullable', 'email', 'max:255'],
            'branch_id' => ['nullable', 'uuid'],
            'department_id' => ['nullable', 'uuid'],
            'job_title' => ['nullable', 'string', 'max:255'],
        ];
    }
}
