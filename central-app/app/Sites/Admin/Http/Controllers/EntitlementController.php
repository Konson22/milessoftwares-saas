<?php

namespace App\Sites\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Models\PlatformStaff;
use App\Modules\Applications\Enums\ApplicationKind;
use App\Modules\Applications\Models\Application;
use App\Modules\Applications\Models\ApplicationFeature;
use App\Modules\Entitlements\Actions\ManageEntitlements;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\Subscription;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Subscriptions and entitlements of one organization. These are platform
 * billing records, so no support access grant is needed.
 */
class EntitlementController extends Controller
{
    public function __construct(
        private readonly ManageEntitlements $entitlements,
        private readonly TenantContext $tenant,
    ) {}

    public function assignPlan(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'uuid', Rule::exists('plans', 'id')->where('status', 'active')],
            'trial' => ['sometimes', 'boolean'],
        ]);

        $this->entitlements->assignPlan(
            $organization,
            Plan::query()->with('applications')->findOrFail($request->string('plan_id')->toString()),
            $this->staff($request),
            (bool) ($validated['trial'] ?? false),
        );

        return $this->done('Plan assigned.');
    }

    public function cancelSubscription(Request $request, Organization $organization, string $subscription): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        // Tenant-scoped lookup: another organization's subscription id 404s.
        $record = $this->tenant->run($organization, fn () => Subscription::query()->findOrFail($subscription));

        $this->entitlements->cancel($organization, $record, $this->staff($request), $validated['reason']);

        return $this->done('Subscription cancelled.');
    }

    public function grant(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validate([
            'application_id' => ['required', 'uuid', Rule::exists('applications', 'id')->whereNot('kind', ApplicationKind::Platform->value)],
            'valid_until' => ['nullable', 'date', 'after:now'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $this->entitlements->grantManual(
            $organization,
            Application::query()->findOrFail($request->string('application_id')->toString()),
            $this->staff($request),
            $validated['reason'],
            isset($validated['valid_until']) ? CarbonImmutable::parse($validated['valid_until']) : null,
        );

        return $this->done('Entitlement granted.');
    }

    /**
     * Grant or deny one registered feature, whatever the organization's plans
     * say. Denials take no limit; a grant without one is unlimited.
     */
    public function feature(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validate([
            'feature_key' => ['required', 'string', 'max:100'],
            'effect' => ['required', Rule::in([Entitlement::GRANT, Entitlement::DENY])],
            'limit' => ['nullable', 'integer', 'min:0', Rule::prohibitedIf($request->input('effect') === Entitlement::DENY)],
            'valid_until' => ['nullable', 'date', 'after:now'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $feature = ApplicationFeature::query()->registered()->where('key', $validated['feature_key'])->first();

        if ($feature === null) {
            throw ValidationException::withMessages(['feature_key' => 'No product declares this feature.']);
        }

        if (isset($validated['limit']) && $feature->limit_unit === null) {
            throw ValidationException::withMessages(['limit' => 'This feature is on or off; it takes no limit.']);
        }

        $this->entitlements->overrideFeature(
            $organization,
            $feature,
            $validated['effect'],
            isset($validated['limit']) ? (int) $validated['limit'] : null,
            $this->staff($request),
            $validated['reason'],
            isset($validated['valid_until']) ? CarbonImmutable::parse($validated['valid_until']) : null,
        );

        return $this->done($validated['effect'] === Entitlement::DENY ? 'Feature denied.' : 'Feature granted.');
    }

    public function revoke(Request $request, Organization $organization, string $entitlement): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $record = $this->tenant->run($organization, fn () => Entitlement::query()->findOrFail($entitlement));

        // A plan's features follow the plan; staff deny them instead.
        if ($record->feature_key !== null && $record->source !== 'manual') {
            throw ValidationException::withMessages(['reason' => 'Features from a plan follow the plan. Deny the feature instead.']);
        }

        $this->entitlements->revoke($organization, $record, $this->staff($request), $validated['reason']);

        return $this->done('Entitlement revoked.');
    }

    private function staff(Request $request): PlatformStaff
    {
        $staff = $request->attributes->get('staff');
        abort_unless($staff instanceof PlatformStaff, 403);

        return $staff;
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => __($message)]);

        return back();
    }
}
