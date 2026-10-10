<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Modules\Audit\AuditLogger;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Actions\RenameOrganizationSlug;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Models\SubscriptionItem;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings of the current organization. Owner-only until scoped roles arrive
 * in Phase 4.
 */
class OrganizationSettingsController extends Controller
{
    public function edit(Request $request, TenantContext $tenant): Response|RedirectResponse
    {
        $organization = $this->ownedOrganization($request, $tenant);

        return Inertia::render('organizations/settings', [
            'details' => [
                'display_name' => $organization->display_name,
                'legal_name' => $organization->legal_name,
                'business_type' => $organization->business_type,
                'contact_email' => $organization->contact_email,
                'contact_phone' => $organization->contact_phone,
                'address' => $organization->address,
                'timezone' => $organization->timezone,
                'default_currency' => $organization->default_currency,
            ],
            'organization' => [
                'displayName' => $organization->display_name,
                'slug' => $organization->primarySlug?->slug,
                'workspaceUrl' => $organization->workspaceUrl(),
            ],
            'rootDomain' => config('platform.root_domain'),
            'redirectGraceDays' => (int) config('platform.slug.redirect_grace_days'),
            'previousSlugs' => $organization->slugs()
                ->whereNotNull('retired_at')
                ->latest('retired_at')
                ->get(['slug', 'retired_at', 'redirect_until'])
                ->map(fn ($slug) => [
                    'slug' => $slug->slug,
                    'redirectsUntil' => $slug->redirect_until?->toDateString(),
                ])
                ->all(),
        ]);
    }

    public function updateSlug(Request $request, TenantContext $tenant, RenameOrganizationSlug $rename): RedirectResponse
    {
        $organization = $this->ownedOrganization($request, $tenant);
        $validated = $request->validate(['slug' => ['required', 'string', 'max:63']]);

        $rename->handle($organization, $validated['slug'], $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Workspace address updated.')]);

        return back();
    }

    public function updateDetails(Request $request, TenantContext $tenant, AuditLogger $audit): RedirectResponse
    {
        $organization = $this->ownedOrganization($request, $tenant);

        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:120'],
            'legal_name' => ['required', 'string', 'max:200'],
            'business_type' => ['nullable', 'string', 'max:48'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:1000'],
            'timezone' => ['required', 'timezone:all'],
            'default_currency' => ['required', 'string', 'size:3', 'alpha'],
        ]);

        $organization->forceFill([...$validated, 'default_currency' => strtoupper($validated['default_currency'])])->save();
        $audit->record('organization.updated', $organization, ['changes' => $organization->getChanges()], organizationId: $organization->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Organization details saved.')]);

        return back();
    }

    /**
     * Read-only: plans and entitlements are changed by Miles Global Softwares
     * staff (admin host) until online billing exists.
     */
    public function subscription(Request $request, TenantContext $tenant): Response
    {
        $organization = $this->ownedOrganization($request, $tenant);

        return Inertia::render('organizations/subscription', [
            'organization' => ['displayName' => $organization->display_name],
            'subscriptions' => Subscription::query()
                ->with('items.plan:id,name,billing_interval,price_minor,currency')
                ->latest()
                ->get()
                ->map(fn (Subscription $subscription) => [
                    'id' => $subscription->id,
                    'status' => $subscription->status,
                    'trialEndsAt' => $subscription->trial_ends_at?->toDateString(),
                    'startedAt' => $subscription->created_at?->toDateString(),
                    'plans' => $subscription->items->map(fn (SubscriptionItem $item) => [
                        'name' => $item->plan->name,
                        'price' => $item->plan->currency.' '.number_format($item->plan->price_minor / 100, 2).' / '.$item->plan->billing_interval,
                        'status' => $item->status,
                    ])->all(),
                ])
                ->all(),
            'entitlements' => Entitlement::query()
                ->current()
                ->whereNull('feature_key')
                ->join('applications', 'applications.id', '=', 'entitlements.application_id')
                ->orderBy('applications.name')
                ->get(['entitlements.id', 'applications.name as application', 'entitlements.source', 'entitlements.valid_until'])
                ->map(fn (Entitlement $entitlement) => [
                    'id' => $entitlement->id,
                    'application' => $entitlement->getAttribute('application'),
                    'source' => $entitlement->source,
                    'validUntil' => $entitlement->valid_until?->toDateString(),
                ])
                ->all(),
        ]);
    }

    private function ownedOrganization(Request $request, TenantContext $tenant): Organization
    {
        $membership = $request->attributes->get('membership');
        $organization = $tenant->organization();

        abort_unless($membership instanceof OrganizationMembership && $organization !== null, 404);
        abort_unless($organization->owner_membership_id === $membership->id, 403);

        return $organization;
    }
}
