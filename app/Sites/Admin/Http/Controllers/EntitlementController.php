<?php

namespace App\Sites\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Models\PlatformStaff;
use App\Modules\Applications\Enums\ApplicationKind;
use App\Modules\Applications\Models\Application;
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

    public function revoke(Request $request, Organization $organization, string $entitlement): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $record = $this->tenant->run($organization, fn () => Entitlement::query()->findOrFail($entitlement));

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
