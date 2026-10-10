<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Models\PlatformStaff;
use App\Modules\Applications\Enums\ApplicationKind;
use App\Modules\Applications\Models\Application;
use App\Modules\Audit\AuditLogger;
use App\Modules\Subscriptions\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PlanController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): Response
    {
        return Inertia::render('admin/plans/index', [
            'plans' => Plan::query()->with('applications:id,name')->orderBy('name')->get()->map(fn (Plan $plan) => [
                'id' => $plan->id,
                'code' => $plan->code,
                'name' => $plan->name,
                'description' => $plan->description,
                'billingInterval' => $plan->billing_interval,
                'priceMinor' => $plan->price_minor,
                'currency' => $plan->currency,
                'trialDays' => $plan->trial_days,
                'isPublic' => $plan->is_public,
                'status' => $plan->status,
                'applicationIds' => $plan->applications->pluck('id')->all(),
                'applications' => $plan->applications->pluck('name')->all(),
            ])->all(),
            'applications' => Application::query()
                ->where('kind', '!=', ApplicationKind::Platform->value)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePlan($request);

        $plan = DB::transaction(function () use ($validated): Plan {
            $plan = Plan::query()->forceCreate($this->attributes($validated));
            $plan->applications()->sync($validated['application_ids']);

            return $plan;
        });

        $this->record('plan.created', $plan, $request, ['code' => $plan->code]);

        return $this->done('Plan created.');
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $validated = $this->validatePlan($request, $plan);

        DB::transaction(function () use ($plan, $validated): void {
            $plan->forceFill($this->attributes($validated))->save();
            $plan->applications()->sync($validated['application_ids']);
        });

        $this->record('plan.updated', $plan, $request, ['changes' => $plan->getChanges()]);

        return $this->done('Plan updated.');
    }

    /**
     * @return array{code: string, name: string, description?: string|null, billing_interval: string, price_minor: int, currency: string, trial_days: int, is_public?: bool, status: string, application_ids: list<string>}
     */
    private function validatePlan(Request $request, ?Plan $plan = null): array
    {
        /** @var array{code: string, name: string, description?: string|null, billing_interval: string, price_minor: int, currency: string, trial_days: int, is_public?: bool, status: string, application_ids: list<string>} */
        return $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_-]*$/', Rule::unique('plans', 'code')->ignore($plan?->id)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'billing_interval' => ['required', Rule::in(['monthly', 'yearly', 'custom'])],
            'price_minor' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'trial_days' => ['required', 'integer', 'between:0,365'],
            'is_public' => ['sometimes', 'boolean'],
            'status' => ['required', Rule::in(['draft', 'active', 'retired'])],
            'application_ids' => ['required', 'array', 'min:1'],
            'application_ids.*' => ['uuid', Rule::exists('applications', 'id')->whereNot('kind', ApplicationKind::Platform->value)],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(array $validated): array
    {
        $applicationCount = count((array) $validated['application_ids']);

        return [
            'code' => $validated['code'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'billing_interval' => $validated['billing_interval'],
            'price_minor' => $validated['price_minor'],
            'currency' => strtoupper((string) $validated['currency']),
            'trial_days' => $validated['trial_days'],
            'is_public' => (bool) ($validated['is_public'] ?? false),
            'is_bundle' => $applicationCount > 1,
            'status' => $validated['status'],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(string $action, Plan $plan, Request $request, array $context): void
    {
        $staff = $request->attributes->get('staff');
        $this->audit->record($action, $plan, $context, actor: $staff instanceof PlatformStaff ? $staff->user : null, actorType: 'staff');
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => __($message)]);

        return back();
    }
}
