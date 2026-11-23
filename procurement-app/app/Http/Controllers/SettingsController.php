<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Procurement\Approvals;
use App\Procurement\AuditTrail;
use App\Procurement\Departments;
use App\Procurement\Models\ApprovalRule;
use App\Procurement\Money;
use App\Procurement\Sourcing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Money\EntryCurrencies;

/**
 * Procurement settings (procurement.settings.manage): separation of duties,
 * quotations and plans, method limits, approval rules, currencies and the
 * Finance connection. Nothing here is hardcoded per organization.
 */
class SettingsController extends Controller
{
    public function show(): Response
    {
        $settings = Workspace::settings();
        $base = $settings->currency;

        return Inertia::render('settings/index', [
            'settings' => [
                'allow_self_approval' => $settings->allow_self_approval,
                'require_plan' => $settings->require_plan,
                'min_quotations' => $settings->min_quotations,
                'invoice_tolerance_percent' => $settings->invoice_tolerance_percent,
                'delivery_address' => $settings->delivery_address ?? '',
                'order_terms' => $settings->order_terms ?? '',
                'finance_bills_enabled' => $settings->finance_bills_enabled,
                'finance_expense_account' => $settings->finance_expense_account ?? '',
                'method_limits' => collect(Sourcing::LABELS)->mapWithKeys(fn ($label, $method) => [
                    $method => ($limit = $settings->methodLimit($method)) === null ? '' : Money::major($limit, $base),
                ]),
            ],
            'currency' => $base,
            'money' => app(EntryCurrencies::class)->forPage(),
            'rules' => ApprovalRule::query()->orderBy('document_type')->orderBy('min_base_minor')->get()->map(fn (ApprovalRule $r) => [
                'id' => $r->id, 'document_type' => $r->getAttribute('document_type'), 'name' => $r->getAttribute('name'),
                'min_amount' => Money::major($r->min_base_minor, $base), 'max_amount' => $r->max_base_minor === null ? '' : Money::major($r->max_base_minor, $base),
                'department_id' => $r->getAttribute('department_id') ?? '', 'department' => $r->getAttribute('department_name'),
                'method' => $r->getAttribute('method') ?? '', 'steps' => $r->steps, 'active' => $r->active,
            ]),
            'documentTypes' => collect(Approvals::LABELS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'stepPermissions' => collect(Approvals::STEP_PERMISSIONS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'members' => WorkspaceMember::options(),
            'departments' => Departments::options(),
            'methods' => self::methodOptions(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'allow_self_approval' => ['required', 'boolean'],
            'require_plan' => ['required', 'boolean'],
            'min_quotations' => ['required', 'integer', 'min:1', 'max:20'],
            'invoice_tolerance_percent' => ['required', 'integer', 'min:0', 'max:25'],
            'delivery_address' => ['nullable', 'string', 'max:2000'],
            'order_terms' => ['nullable', 'string', 'max:5000'],
            'finance_bills_enabled' => ['required', 'boolean'],
            'finance_expense_account' => ['nullable', 'required_if_accepted:finance_bills_enabled', 'string', 'max:32'],
            'method_limits' => ['nullable', 'array'],
            'method_limits.*' => ['nullable'],
        ], ['finance_expense_account.required_if_accepted' => 'Name the Finance expense account bills are charged to; without one Finance would post them to inventory.']);

        $settings = Workspace::settings();
        $limits = [];
        foreach ((array) ($data['method_limits'] ?? []) as $method => $value) {
            if (! array_key_exists($method, Sourcing::LABELS)) {
                continue;
            }
            if ($value !== null && trim((string) $value) !== '') {
                $limits[$method] = Money::parse($value, $settings->currency, "method_limits.{$method}");
            }
        }

        DB::transaction(function () use ($settings, $data, $limits) {
            $settings->fill([...$data, 'method_limits' => $limits ?: null]);
            $changes = array_keys($settings->getDirty());
            $settings->save();
            AuditTrail::record('settings.updated', $settings, $this->user(), ['changed' => $changes]);
        });

        return back()->with('success', 'Settings saved.');
    }

    public function saveRule(Request $request, ?ApprovalRule $rule = null): RedirectResponse
    {
        $data = $request->validate([
            'document_type' => ['required', Rule::in(Approvals::TYPES)],
            'name' => ['required', 'string', 'max:255'],
            'min_amount' => ['nullable'],
            'max_amount' => ['nullable'],
            'department_id' => ['nullable', 'string', 'max:64'],
            'method' => ['nullable', Rule::in(array_keys(Sourcing::LABELS))],
            'active' => ['boolean'],
            'steps' => ['required', 'array', 'min:1', 'max:6'],
            'steps.*.type' => ['required', Rule::in(['permission', 'member'])],
            'steps.*.value' => ['required', 'string', 'max:64'],
        ]);

        $base = Workspace::settings()->currency;
        $members = collect(WorkspaceMember::options())->pluck('label', 'value');
        $steps = [];
        foreach ($data['steps'] as $i => $step) {
            if ($step['type'] === 'permission') {
                if (! isset(Approvals::STEP_PERMISSIONS[$step['value']])) {
                    throw ValidationException::withMessages(["steps.{$i}.value" => 'Choose who approves.']);
                }
                $label = Approvals::STEP_PERMISSIONS[$step['value']];
            } else {
                if (! $members->has($step['value'])) {
                    throw ValidationException::withMessages(["steps.{$i}.value" => 'Choose a member of this organization.']);
                }
                $label = (string) str($members[$step['value']])->before(' (');
            }
            $steps[] = ['type' => $step['type'], 'value' => $step['value'], 'label' => $label];
        }

        $min = trim((string) ($data['min_amount'] ?? '')) === '' ? 0 : Money::parse($data['min_amount'], $base, 'min_amount');
        $max = trim((string) ($data['max_amount'] ?? '')) === '' ? null : Money::parse($data['max_amount'], $base, 'max_amount');
        if ($max !== null && $max < $min) {
            throw ValidationException::withMessages(['max_amount' => 'The upper amount is below the lower one.']);
        }

        DB::transaction(function () use ($rule, $data, $steps, $min, $max) {
            $model = $rule ?? new ApprovalRule;
            $model->fill([
                'document_type' => $data['document_type'],
                'name' => $data['name'],
                'min_base_minor' => $min,
                'max_base_minor' => $max,
                'department_id' => ($data['department_id'] ?? null) ?: null,
                'department_name' => Departments::name($data['department_id'] ?? null),
                'method' => ($data['method'] ?? null) ?: null,
                'steps' => $steps,
                'active' => $data['active'] ?? true,
            ])->save();
            // Submissions already waiting keep the steps they were given.
            AuditTrail::record($rule === null ? 'approval_rule.created' : 'approval_rule.updated', $model, $this->user(), ['steps' => count($steps)]);
        });

        return back()->with('success', 'Approval rule saved. It applies to submissions from now on.');
    }
}
