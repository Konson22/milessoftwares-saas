<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeComponent;
use App\Models\PayComponent;
use App\Payroll\AuditTrail;
use App\Payroll\Staff;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * One person's pay: basic pay from a date, their allowances and
 * deductions, and the account they are paid into. Organization-wide
 * payroll.pay.manage only (the routes say so).
 */
class EmployeePayController extends Controller
{
    public function storePay(Request $request, Employee $employee, Staff $staff, AuditTrail $audit): RedirectResponse
    {
        $this->ensureActive($employee);
        $validated = $request->validate([
            'effective_from' => ['required', 'date'],
            'amount' => Money::RULES,
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $pay = $staff->setPay($employee, CarbonImmutable::parse($validated['effective_from']), Money::toMinor($validated['amount']), $validated['note'] ?? null, $this->user($request));
        $audit->record('employee.pay_set', $employee, $this->user($request), ['effective_from' => $pay->effective_from->toDateString(), 'amount_minor' => $pay->amount_minor, 'note' => $pay->note]);

        return back()->with('success', 'Pay saved.');
    }

    public function storeComponent(Request $request, Employee $employee, AuditTrail $audit): RedirectResponse
    {
        $this->ensureActive($employee);
        $validated = $request->validate([
            'pay_component_id' => ['required', 'uuid'],
            'amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ]);

        $component = PayComponent::query()->where('active', true)->whereKey((string) $validated['pay_component_id'])->first();
        if ($component === null) {
            throw ValidationException::withMessages(['pay_component_id' => 'Choose an allowance or deduction in use.']);
        }

        $percent = $component->calculation === PayComponent::PERCENT;
        $value = $percent ? ($validated['rate'] ?? null) : ($validated['amount'] ?? null);
        if ($value === null) {
            throw ValidationException::withMessages([$percent ? 'rate' : 'amount' => $percent ? 'Enter the percentage of basic pay.' : 'Enter the amount.']);
        }

        $assigned = new EmployeeComponent([
            'employee_id' => $employee->id,
            'pay_component_id' => $component->id,
            'amount_minor' => $percent ? null : Money::toMinor($value),
            'rate_bp' => $percent ? Money::hundredths($value) : null,
            'starts_on' => $validated['starts_on'],
            'ends_on' => $validated['ends_on'] ?? null,
        ]);
        $assigned->save();
        $audit->record('employee.component_added', $employee, $this->user($request), [
            'component' => $component->name,
            'amount_minor' => $assigned->amount_minor,
            'rate_bp' => $assigned->rate_bp,
            'starts_on' => $validated['starts_on'],
            'ends_on' => $validated['ends_on'] ?? null,
        ]);

        return back()->with('success', "{$component->name} added.");
    }

    public function endComponent(Request $request, Employee $employee, EmployeeComponent $assigned, AuditTrail $audit): RedirectResponse
    {
        abort_unless($assigned->employee_id === $employee->id, 404);
        $validated = $request->validate(['ends_on' => ['required', 'date', Rule::date()->afterOrEqual($assigned->starts_on->toDateString())]]);

        $assigned->forceFill(['ends_on' => $validated['ends_on']])->save();
        $audit->record('employee.component_ended', $employee, $this->user($request), ['component' => $assigned->component->name, 'ends_on' => $validated['ends_on']]);

        return back()->with('success', 'Ended.');
    }

    public function updatePaymentDetails(Request $request, Employee $employee, AuditTrail $audit): RedirectResponse
    {
        $validated = $request->validate([
            'method' => ['required', Rule::in(['bank', 'mobile_money', 'cash'])],
            'bank_name' => ['nullable', 'required_if:method,bank', 'string', 'max:100'],
            'account_name' => ['nullable', 'string', 'max:150'],
            'account_number' => ['nullable', 'required_if:method,bank', 'string', 'max:50'],
            'mobile_number' => ['nullable', 'required_if:method,mobile_money', 'string', 'max:32'],
        ]);

        $employee->forceFill(['payment_details' => $validated])->save();
        // Which way they are paid, never the account itself.
        $audit->record('employee.payment_details_changed', $employee, $this->user($request), ['method' => $validated['method']]);

        return back()->with('success', 'Payment details saved.');
    }

    private function ensureActive(Employee $employee): void
    {
        abort_unless($employee->isActive(), 403, 'They have left: their pay no longer changes.');
    }
}
