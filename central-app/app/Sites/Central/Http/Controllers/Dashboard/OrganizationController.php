<?php

namespace App\Sites\Central\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Modules\Memberships\Support\CurrentOrganization;
use App\Modules\Organizations\Actions\CheckSlugAvailability;
use App\Modules\Organizations\Actions\CreateOrganization;
use App\Sites\Central\Http\Requests\Dashboard\CreateOrganizationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('central/organizations/create', [
            'rootDomain' => config('platform.root_domain'),
            'defaults' => ['country_code' => 'SS', 'default_currency' => 'SSP', 'timezone' => 'Africa/Juba'],
        ]);
    }

    public function store(CreateOrganizationRequest $request, CreateOrganization $create, CurrentOrganization $current): RedirectResponse
    {
        $organization = $create->handle($request->user(), $request->validated());

        $current->switchTo($request, $request->user(), $organization->getKey());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name is ready.', ['name' => $organization->display_name])]);

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
