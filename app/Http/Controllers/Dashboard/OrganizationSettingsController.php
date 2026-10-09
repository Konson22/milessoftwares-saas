<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Actions\RenameOrganizationSlug;
use App\Modules\Organizations\Models\Organization;
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

    private function ownedOrganization(Request $request, TenantContext $tenant): Organization
    {
        $membership = $request->attributes->get('membership');
        $organization = $tenant->organization();

        abort_unless($membership instanceof OrganizationMembership && $organization !== null, 404);
        abort_unless($organization->owner_membership_id === $membership->id, 403);

        return $organization;
    }
}
