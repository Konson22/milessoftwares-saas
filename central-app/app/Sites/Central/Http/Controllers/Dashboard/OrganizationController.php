<?php

namespace App\Sites\Central\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Modules\Applications\Models\Application;
use App\Modules\Entitlements\Actions\ManageEntitlements;
use App\Modules\Marketing\Support\MarketingCatalog;
use App\Modules\Organizations\Actions\AddFirstBranch;
use App\Modules\Organizations\Actions\CheckSlugAvailability;
use App\Modules\Organizations\Actions\CreateOrganization;
use App\Modules\Organizations\Models\Organization;
use App\Sites\Central\Http\Requests\Dashboard\CreateOrganizationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class OrganizationController extends Controller
{
    /**
     * A new business, on the auth host. ?app=<code> comes from "Start free
     * trial" and preselects that application's trial.
     */
    public function create(Request $request, MarketingCatalog $catalog): Response
    {
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

    public function store(CreateOrganizationRequest $request, CreateOrganization $create, AddFirstBranch $firstBranch, ManageEntitlements $entitlements): SymfonyResponse
    {
        // The business and its first branch together, or neither.
        $organization = DB::transaction(function () use ($create, $firstBranch, $request): Organization {
            $organization = $create->handle($request->user(), $request->validated());
            $firstBranch->handle($organization, $request->user());

            return $organization;
        });

        $trial = $request->trialApplication();
        if ($trial !== null) {
            $entitlements->startTrial($organization, Application::query()->where('code', $trial)->sole(), $request->user());
        }

        // The business has its own host and session: open it there (signing in
        // to it is silent, the auth host session is already there).
        return Inertia::location((string) $organization->refresh()->workspaceUrl());
    }

    public function slugAvailability(Request $request, CheckSlugAvailability $slugs): JsonResponse
    {
        $slug = (string) $request->query('slug', '');
        $errors = $slugs->errors($slug);

        return response()->json(['available' => $errors === [], 'errors' => $errors]);
    }
}
