<?php

namespace App\Http\Controllers;

use App\Models\PayComponent;
use App\Models\StatutoryRule;
use App\Payroll\AuditTrail;
use App\Payroll\Presets;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organization's allowances and deductions, and its income tax and
 * social security rules from a date (a change is a new set of rules, so
 * past payrolls keep the rules they were paid under).
 */
class SettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('settings/index', [
            'components' => PayComponent::query()->orderBy('kind')->orderBy('name')->get()->map(fn (PayComponent $component) => [
                'id' => $component->id,
                'name' => $component->name,
                'kind' => $component->kind,
                'calculation' => $component->calculation,
                'taxable' => $component->taxable,
                'active' => $component->active,
            ]),
            'rules' => StatutoryRule::query()->with('creator:id,name')->orderByDesc('effective_from')->get()->map(fn (StatutoryRule $rule) => [
                'id' => $rule->id,
                'name' => $rule->name,
                'effectiveFrom' => $rule->effective_from->toDateString(),
                'bands' => $rule->income_tax_bands,
                'employeeBp' => $rule->social_security_employee_bp,
                'employerBp' => $rule->social_security_employer_bp,
                'capMinor' => $rule->social_security_cap_minor,
                'deductible' => $rule->social_security_deductible,
                'by' => $rule->creator->name,
            ]),
            'presets' => collect(Presets::all())->map(fn (array $preset, string $code) => [
                'code' => $code,
                ...$preset,
            ])->values(),
        ]);
    }

    public function storeComponent(Request $request, AuditTrail $audit): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('pay_components', 'name')->where('organization_id', $this->organizationId())],
            'kind' => ['required', Rule::in([PayComponent::EARNING, PayComponent::DEDUCTION])],
            'calculation' => ['required', Rule::in([PayComponent::FIXED, PayComponent::PERCENT])],
            'taxable' => ['boolean'],
        ]);

        $component = new PayComponent([
            ...$validated,
            // A deduction never changes taxable pay.
            'taxable' => $validated['kind'] === PayComponent::EARNING && $request->boolean('taxable'),
            'active' => true,
        ]);
        $component->save();
        $audit->record('settings.component_added', $component, $this->user($request), ['kind' => $component->kind, 'calculation' => $component->calculation, 'taxable' => $component->taxable]);

        return back()->with('success', "{$validated['name']} added.");
    }

    public function updateComponent(Request $request, PayComponent $component, AuditTrail $audit): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('pay_components', 'name')->where('organization_id', $this->organizationId())->ignore($component->id)],
            'taxable' => ['boolean'],
            'active' => ['boolean'],
        ]);

        $component->fill([
            'name' => $validated['name'],
            'taxable' => $component->kind === PayComponent::EARNING && $request->boolean('taxable'),
            'active' => $request->boolean('active'),
        ])->save();
        $changes = array_diff_key($component->getChanges(), ['updated_at' => true]);
        if ($changes !== []) {
            $audit->record('settings.component_changed', $component, $this->user($request), $changes);
        }

        return back()->with('success', 'Saved.');
    }

    public function storeRule(Request $request, AuditTrail $audit): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'effective_from' => ['required', 'date'],
            'bands' => ['required', 'array', 'min:1', 'max:20'],
            'bands.*.up_to' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'bands.*.rate' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'employee_rate' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'employer_rate' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'cap' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'deductible' => ['boolean'],
        ], [], ['effective_from' => 'start date']);

        if (StatutoryRule::query()->whereDate('effective_from', $validated['effective_from'])->exists()) {
            throw ValidationException::withMessages(['effective_from' => 'Rules already start on that date: choose another date.']);
        }

        $bands = [];
        $previous = -1;
        foreach (array_values($validated['bands']) as $i => $band) {
            $last = $i === count($validated['bands']) - 1;
            $upTo = ($band['up_to'] ?? '') === '' ? null : Money::toMinor($band['up_to']);
            if ($upTo === null && ! $last) {
                throw ValidationException::withMessages(["bands.{$i}.up_to" => 'Only the last band is open-ended.']);
            }
            if ($upTo !== null && $last) {
                throw ValidationException::withMessages(["bands.{$i}.up_to" => 'Leave the last band open-ended: it covers everything above.']);
            }
            if ($upTo !== null && $upTo <= $previous) {
                throw ValidationException::withMessages(["bands.{$i}.up_to" => 'Each band must go higher than the one before.']);
            }
            $previous = $upTo ?? $previous;
            $bands[] = ['up_to_minor' => $upTo, 'rate_bp' => Money::hundredths($band['rate'])];
        }

        $rule = new StatutoryRule([
            'name' => $validated['name'],
            'effective_from' => $validated['effective_from'],
            'income_tax_bands' => $bands,
            'social_security_employee_bp' => Money::hundredths($validated['employee_rate']),
            'social_security_employer_bp' => Money::hundredths($validated['employer_rate']),
            'social_security_cap_minor' => isset($validated['cap']) ? Money::toMinor($validated['cap']) : null,
            'social_security_deductible' => $request->boolean('deductible'),
            'created_by' => $this->user($request)->id,
        ]);
        $rule->save();
        $audit->record('settings.rule_added', $rule, $this->user($request), [
            'bands' => count($bands),
            'social_security_employee_bp' => $rule->social_security_employee_bp,
            'social_security_employer_bp' => $rule->social_security_employer_bp,
        ]);

        return back()->with('success', "Rules from {$validated['effective_from']} saved.");
    }
}
