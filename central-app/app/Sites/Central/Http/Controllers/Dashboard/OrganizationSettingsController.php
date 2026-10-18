<?php

namespace App\Sites\Central\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Modules\Audit\AuditLogger;
use App\Modules\Organizations\Actions\RenameOrganizationSlug;
use App\Sites\Central\Http\Controllers\Dashboard\Concerns\ResolvesOwnedOrganization;
use App\Support\Tenancy\TenantContext;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * General settings of the current organization: details, regional
 * defaults, billing details and the workspace address. Owner-only until
 * scoped roles arrive in Phase 4.
 */
class OrganizationSettingsController extends Controller
{
    use ResolvesOwnedOrganization;

    public function edit(Request $request, TenantContext $tenant): Response|RedirectResponse
    {
        $organization = $this->ownedOrganization($request, $tenant);

        return Inertia::render('central/organizations/settings', [
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
            'billing' => [
                'billing_email' => $organization->billing_email,
                'billing_address' => $organization->billing_address,
                'tax_id' => $organization->tax_id,
            ],
            'organization' => [
                'displayName' => $organization->display_name,
                'slug' => $organization->primarySlug?->slug,
                'workspaceUrl' => $organization->workspaceUrl(),
                'countryCode' => $organization->country_code,
                'status' => $organization->status->value,
                'createdAt' => $organization->created_at?->toDateString(),
            ],
            'timezones' => DateTimeZone::listIdentifiers(),
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
     * Who invoices are addressed to. Empty fields fall back to the
     * organization's contact details.
     */
    public function updateBilling(Request $request, TenantContext $tenant, AuditLogger $audit): RedirectResponse
    {
        $organization = $this->ownedOrganization($request, $tenant);

        $validated = $request->validate([
            'billing_email' => ['nullable', 'email', 'max:255'],
            'billing_address' => ['nullable', 'string', 'max:1000'],
            'tax_id' => ['nullable', 'string', 'max:64'],
        ]);

        $organization->forceFill($validated)->save();
        $audit->record('organization.billing_updated', $organization, ['changes' => $organization->getChanges()], organizationId: $organization->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Billing details saved.')]);

        return back();
    }
}
