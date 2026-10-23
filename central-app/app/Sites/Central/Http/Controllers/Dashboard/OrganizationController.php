<?php

namespace App\Sites\Central\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Modules\Applications\Models\Application;
use App\Modules\Entitlements\Actions\ManageEntitlements;
use App\Modules\Marketing\Support\MarketingCatalog;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Memberships\Support\CurrentOrganization;
use App\Modules\Organizations\Actions\CheckSlugAvailability;
use App\Modules\Organizations\Actions\CreateOrganization;
use App\Sites\Central\Http\Requests\Dashboard\CreateOrganizationRequest;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    /**
     * ?app=<code> comes from "Start free trial" on the public site and
     * preselects that application's trial. The owner of the current business
     * adds it there instead (the trial page), without a new name and address.
     */
    public function create(Request $request, MarketingCatalog $catalog, TenantContext $tenant): Response|RedirectResponse
    {
        $membership = $request->attributes->get('membership');
        if ($request->filled('app')
            && $membership instanceof OrganizationMembership
            && $tenant->organization()?->owner_membership_id === $membership->id) {
            return redirect()->route('trial', ['app' => $request->string('app')->toString()]);
        }

        $trialable = array_map(fn (array $product) => [
            'code' => $product['code'],
            'name' => $product['name'],
            'tagline' => $product['tagline'] ?? $product['description'],
        ], $catalog->trialable());
        $selected = $request->string('app')->toString();

        return Inertia::render('central/organizations/create', [
            'rootDomain' => config('platform.root_domain'),
            'defaults' => ['country_code' => 'SS', 'default_currency' => 'SSP', 'timezone' => 'Africa/Juba'],
            'trial' => [
                'days' => (int) config('platform.marketing.trial_days'),
                'applications' => $trialable,
                'selected' => in_array($selected, array_column($trialable, 'code'), true) ? $selected : null,
            ],
        ]);
    }

    public function store(CreateOrganizationRequest $request, CreateOrganization $create, CurrentOrganization $current, ManageEntitlements $entitlements): RedirectResponse
    {
        $organization = $create->handle($request->user(), $request->validated());

        $current->switchTo($request, $request->user(), $organization->getKey());

        $trial = $request->trialApplication();
        if ($trial !== null) {
            $entitlements->startTrial($organization, Application::query()->where('code', $trial)->sole(), $request->user());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $trial === null
            ? __(':name is ready.', ['name' => $organization->display_name])
            : __(':name is ready. Your :days-day trial has started.', ['name' => $organization->display_name, 'days' => (int) config('platform.marketing.trial_days')]),
        ]);

        return redirect()->route('dashboard');
    }

    public function switch(Request $request, CurrentOrganization $current, string $organization): RedirectResponse
    {
        // Same response for "not a member" and "no such organization".
        abort_if($current->switchTo($request, $request->user(), $organization) === null, 404);

        return redirect()->route('dashboard');
    }

    public function slugAvailability(Request $request, CheckSlugAvailability $slugs): JsonResponse
    {
        $slug = (string) $request->query('slug', '');
        $errors = $slugs->errors($slug);

        return response()->json(['available' => $errors === [], 'errors' => $errors]);
    }
}
