<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Organizations;
use App\Actions\PettyCash;
use App\Actions\StaffAdvances;
use App\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\ApprovalPolicy;
use App\Models\Organization;
use App\Models\Partnership;
use App\Support\Modules;
use App\Support\Money;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Administration → Settings: what Projects does for the organization. Its
 * name, address, currency and timezone are the platform's (the workspace's
 * own settings there), shown here as they are.
 */
class SettingsController extends Controller
{
    private const HEALTH = ['health_schedule_slack', 'health_budget_slack'];

    public function edit(Request $request): Response
    {
        $organization = app(CurrentOrganization::class)->require();

        return Inertia::render('portal/admin/settings', [
            'settings' => [
                'name' => $organization->name,
                'slug' => $organization->slug,
                'currency' => $organization->currency,
                'timezone' => $organization->timezone,
                'modules' => collect(Modules::ALL)->map(fn (array $module, string $key) => [
                    'key' => $key,
                    'name' => $module['name'],
                    'description' => $module['description'],
                    'enabled' => $organization->hasModule($key),
                ])->values()->all(),
                'funding' => [
                    'enabled' => $organization->isDonor(),
                    'canTurnOff' => ! Partnership::query()->where('donor_organization_id', $organization->id)->exists(),
                ],
                'financeLimits' => $organization->hasModule(Modules::ACCOUNTING) ? [
                    'currency' => $organization->currency,
                    'pettyCashMax' => $organization->petty_cash_max_payment_minor === null ? null : Money::toMajor($organization->petty_cash_max_payment_minor),
                    'advanceMax' => $organization->advance_max_minor === null ? null : Money::toMajor($organization->advance_max_minor),
                    'advanceRetireDays' => $organization->advance_retire_days,
                ] : null,
                'health' => [
                    'scheduleSlack' => $organization->health_schedule_slack,
                    'budgetSlack' => $organization->health_budget_slack,
                ],
            ],
            'approvalPolicies' => ApprovalPolicy::query()->where('subject', ApprovalPolicy::EXPENSE)
                ->orderBy('currency')->orderBy('min_amount_minor')->get()
                ->map(fn (ApprovalPolicy $policy) => [
                    'id' => $policy->id,
                    'currency' => $policy->currency,
                    'minAmountMinor' => $policy->min_amount_minor,
                    'approvalsRequired' => $policy->approvals_required,
                ])->values()->all(),
            'maxApprovers' => ApprovalPolicy::MAX_APPROVERS,
        ]);
    }

    public function updateModule(Request $request, string $module): RedirectResponse
    {
        $validated = $request->validate(['enabled' => ['required', 'boolean']]);
        $on = filter_var($validated['enabled'], FILTER_VALIDATE_BOOLEAN);

        Organizations::setModule(app(CurrentOrganization::class)->require(), $module, $on, $this->member($request));

        return back()->with('success', Modules::ALL[$module]['name'].($on ? ' is on.' : ' is off. Nothing in it was deleted.'));
    }

    /**
     * Whether this organization also funds others.
     */
    public function updateFunding(Request $request): SymfonyResponse
    {
        $organization = app(CurrentOrganization::class)->require();
        $validated = $request->validate(['funds' => ['required', 'boolean']]);
        $funds = filter_var($validated['funds'], FILTER_VALIDATE_BOOLEAN);

        Organizations::setFunding($organization, $funds, $this->member($request));
        $message = $funds ? 'You can now fund other organizations.' : 'Funding is off.';

        return back()->with('success', $message);
    }

    /**
     * What the organization allows from petty cash and as advances. With nothing
     * set, nothing is paid or advanced.
     */
    public function updateFinanceLimits(Request $request, PettyCash $pettyCash, StaffAdvances $advances): RedirectResponse
    {
        abort_unless(app(CurrentOrganization::class)->require()->hasModule(Modules::ACCOUNTING), 404);

        $validated = $request->validate([
            'petty_cash_max_payment' => ['nullable', 'string', Money::rule()],
            'advance_max' => ['nullable', 'string', Money::rule()],
            'advance_retire_days' => ['nullable', 'integer', 'between:1,365'],
        ]);
        $amount = $validated['petty_cash_max_payment'] ?? null;
        $pettyCash->setLimit(blank($amount) ? null : Money::toMinor((string) $amount), $this->member($request));
        $advanceMax = $validated['advance_max'] ?? null;
        $days = $validated['advance_retire_days'] ?? null;
        $advances->setPolicy(blank($advanceMax) ? null : Money::toMinor((string) $advanceMax), blank($days) ? null : (int) $days, $this->member($request));

        return back()->with('success', 'Limits saved.');
    }

    /**
     * How far projects may slip before they show "at risk".
     */
    public function updateHealth(Request $request): RedirectResponse
    {
        $organization = app(CurrentOrganization::class)->require();
        $validated = $request->validate([
            'health_schedule_slack' => ['required', 'integer', 'between:0,100'],
            'health_budget_slack' => ['required', 'integer', 'between:0,100'],
        ]);

        $before = $organization->only(self::HEALTH);
        $organization->fill([
            'health_schedule_slack' => (int) $validated['health_schedule_slack'],
            'health_budget_slack' => (int) $validated['health_budget_slack'],
        ])->save();

        Audit::change('organization.health_thresholds_updated', $organization, $before, $organization->only(self::HEALTH));

        return back()->with('success', 'Project health thresholds saved.');
    }
}
