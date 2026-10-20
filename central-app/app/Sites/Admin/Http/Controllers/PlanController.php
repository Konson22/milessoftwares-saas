<?php

namespace App\Sites\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Models\PlatformStaff;
use App\Modules\Applications\Enums\ApplicationKind;
use App\Modules\Applications\Models\Application;
use App\Modules\Applications\Models\ApplicationFeature;
use App\Modules\Audit\AuditLogger;
use App\Modules\Entitlements\Jobs\SyncPlanFeatures;
use App\Modules\Products\Models\ErpProduct;
use App\Modules\Products\Support\ProductPlans;
use App\Modules\Subscriptions\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PlanController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): Response
    {
        $planFeatures = DB::table('plan_features')->orderBy('feature_key')->get()->groupBy('plan_id');

        return Inertia::render('admin/plans/index', [
            'plans' => Plan::query()->with(['applications:id,name', 'product:id,name'])->orderBy('name')->get()->map(fn (Plan $plan) => [
                'id' => $plan->id,
                'productId' => $plan->erp_product_id,
                'product' => $plan->product?->name,
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
                'features' => $planFeatures->get($plan->id, collect())->map(fn (object $feature) => [
                    'key' => $feature->feature_key,
                    'applicationId' => $feature->application_id,
                    'limit' => $feature->limit_value === null ? null : (int) $feature->limit_value,
                ])->values()->all(),
            ])->all(),
            // Only what products declare in their current catalogs can be sold.
            'features' => ApplicationFeature::query()->registered()->orderBy('key')->get()->map(fn (ApplicationFeature $feature) => [
                'key' => $feature->key,
                'name' => $feature->name,
                'description' => $feature->description,
                'status' => $feature->status,
                'limitUnit' => $feature->limit_unit,
                'applicationId' => $feature->application_id,
            ])->all(),
            'applications' => Application::query()
                ->where('kind', '!=', ApplicationKind::Platform->value)
                ->orderBy('name')
                ->get(['id', 'name']),
            'products' => ErpProduct::query()->orderBy('sort_order')->orderBy('name')->get()->map(fn (ErpProduct $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'status' => $product->status->value,
            ])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePlan($request);

        $plan = DB::transaction(function () use ($validated): Plan {
            $plan = Plan::query()->forceCreate($this->attributes($validated));
            $plan->applications()->sync($validated['application_ids']);
            $this->syncFeatures($plan, $validated['features'] ?? []);

            return $plan;
        });

        $this->record('plan.created', $plan, $request, ['code' => $plan->code, 'features' => array_keys($validated['features'] ?? [])]);

        return $this->done('Plan created.');
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $validated = $this->validatePlan($request, $plan);

        $featureChanges = DB::transaction(function () use ($plan, $validated): array {
            $plan->forceFill($this->attributes($validated))->save();
            $plan->applications()->sync($validated['application_ids']);

            return isset($validated['features']) ? $this->syncFeatures($plan, $validated['features']) : [];
        });

        $this->record('plan.updated', $plan, $request, array_filter(['changes' => $plan->getChanges(), 'features' => $featureChanges]));

        // Organizations already on the plan follow it; their overrides stay.
        if ($featureChanges !== []) {
            SyncPlanFeatures::dispatch($plan->id);
        }

        return $this->done('Plan updated.');
    }

    /**
     * A plan selling an ERP product must cover what the product requires
     * and nothing outside it (ProductPlans).
     *
     * @return array{erp_product_id?: string|null, code: string, name: string, description?: string|null, billing_interval: string, price_minor: int, currency: string, trial_days: int, is_public?: bool, status: string, application_ids: list<string>, features?: array<string, array{application_id: string, limit: int|null}>}
     *
     * @throws ValidationException
     */
    private function validatePlan(Request $request, ?Plan $plan = null): array
    {
        /** @var array{erp_product_id?: string|null, code: string, name: string, description?: string|null, billing_interval: string, price_minor: int, currency: string, trial_days: int, is_public?: bool, status: string, application_ids: list<string>} $validated */
        $validated = $request->validate([
            'erp_product_id' => ['nullable', 'uuid', Rule::exists('erp_products', 'id')],
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_-]*$/', Rule::unique('plans', 'code')->ignore($plan?->id)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'billing_interval' => ['required', Rule::in(['monthly', 'yearly', 'custom'])],
            'price_minor' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'trial_days' => ['required', 'integer', 'between:0,365'],
            'is_public' => ['sometimes', 'boolean'],
            'status' => ['required', Rule::in(['draft', 'active', 'retired'])],
            'application_ids' => ['required', 'array', 'list', 'min:1'],
            'application_ids.*' => ['uuid', Rule::exists('applications', 'id')->whereNot('kind', ApplicationKind::Platform->value)],
            // Sent by the plan form even when no feature is ticked; older
            // clients that omit it leave the plan's features as they are.
            'manage_features' => ['sometimes', 'boolean'],
            'features' => ['sometimes', 'array', 'list'],
            'features.*.key' => ['required', 'string', 'max:100', 'distinct'],
            'features.*.limit' => ['nullable', 'integer', 'min:0'],
        ]);

        if (isset($validated['erp_product_id'])) {
            $product = ErpProduct::query()->with('applications:id')->findOrFail($validated['erp_product_id']);
            $problems = ProductPlans::problems($product->name, $product->status, $product->requirements(), $validated['application_ids'], $validated['status']);

            if ($problems !== []) {
                throw ValidationException::withMessages($problems);
            }
        }

        unset($validated['features'], $validated['manage_features']);

        if ($request->boolean('manage_features') || $request->has('features')) {
            /** @var list<array{key: string, limit?: int|null}> $requested */
            $requested = (array) $request->input('features', []);
            $validated['features'] = $this->validateFeatures($requested, $validated['application_ids'], $plan);
        }

        return $validated;
    }

    /**
     * Features must be declared by a product's current catalog, belong to an
     * application the plan covers, and take a limit only when they count
     * something. A feature already on the plan stays allowed after its
     * product deprecates or drops it, so existing plans keep saving.
     *
     * @param  list<array{key: string, limit?: int|null}>  $requested
     * @param  list<string>  $applicationIds
     * @return array<string, array{application_id: string, limit: int|null}>
     *
     * @throws ValidationException
     */
    private function validateFeatures(array $requested, array $applicationIds, ?Plan $plan): array
    {
        $keys = array_map(fn (array $feature) => (string) $feature['key'], $requested);
        $registered = ApplicationFeature::query()->registered()->whereIn('key', $keys)->with('application:id,name')->get()->keyBy('key');
        $existing = $plan === null ? collect() : DB::table('plan_features')->where('plan_id', $plan->id)->get()->keyBy('feature_key');
        $features = [];
        $problems = [];

        foreach ($requested as $i => $feature) {
            $key = (string) $feature['key'];
            $limit = isset($feature['limit']) ? (int) $feature['limit'] : null;
            $declared = $registered->get($key);
            $current = $existing->get($key);

            if ($declared === null && $current === null) {
                $problems["features.{$i}.key"] = "No product declares the feature [{$key}].";

                continue;
            }

            if ($declared?->status === 'deprecated' && $current === null) {
                $problems["features.{$i}.key"] = "The feature [{$key}] is deprecated.";

                continue;
            }

            $applicationId = (string) ($declared->application_id ?? $current->application_id);

            if (! in_array($applicationId, $applicationIds, true)) {
                $problems["features.{$i}.key"] = "The feature [{$key}] needs ".($declared?->application->name ?? 'its application').' on the plan.';

                continue;
            }

            if ($limit !== null && $declared !== null && $declared->limit_unit === null) {
                $problems["features.{$i}.limit"] = "The feature [{$key}] is on or off; it takes no limit.";

                continue;
            }

            $features[$key] = ['application_id' => $applicationId, 'limit' => $limit];
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        return $features;
    }

    /**
     * @param  array<string, array{application_id: string, limit: int|null}>  $features
     * @return array{added?: list<string>, removed?: list<string>, limits?: array<string, array{from: int|null, to: int|null}>}
     */
    private function syncFeatures(Plan $plan, array $features): array
    {
        /** @var Collection<string, object> $existing */
        $existing = DB::table('plan_features')->where('plan_id', $plan->id)->get()->keyBy('feature_key');
        $changes = [];

        foreach ($existing as $key => $row) {
            if (! isset($features[$key])) {
                DB::table('plan_features')->where('id', $row->id)->delete();
                $changes['removed'][] = (string) $key;
            }
        }

        foreach ($features as $key => $feature) {
            $row = $existing->get($key);
            $limit = $row?->limit_value === null ? null : (int) $row->limit_value;

            if ($row === null) {
                DB::table('plan_features')->insert([
                    'id' => (string) Str::uuid7(),
                    'plan_id' => $plan->id,
                    'application_id' => $feature['application_id'],
                    'feature_key' => $key,
                    'limit_value' => $feature['limit'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $changes['added'][] = $key;
            } elseif ($limit !== $feature['limit'] || $row->application_id !== $feature['application_id']) {
                DB::table('plan_features')->where('id', $row->id)->update(['application_id' => $feature['application_id'], 'limit_value' => $feature['limit'], 'updated_at' => now()]);
                $changes['limits'][$key] = ['from' => $limit, 'to' => $feature['limit']];
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(array $validated): array
    {
        $applicationCount = count((array) $validated['application_ids']);

        return [
            'erp_product_id' => $validated['erp_product_id'] ?? null,
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
