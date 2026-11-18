<?php

namespace App\Sites\Central\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Modules\Applications\Models\Application;
use App\Modules\Entitlements\Actions\ManageEntitlements;
use App\Modules\Entitlements\Support\TrialRefused;
use App\Modules\Marketing\Support\MarketingCatalog;
use App\Modules\Organizations\Actions\AddFirstBranch;
use App\Modules\Organizations\Actions\CheckSlugAvailability;
use App\Modules\Organizations\Actions\CreateOrganization;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Subscriptions\Actions\RegistrationIntents;
use App\Modules\Subscriptions\Models\RegistrationIntent;
use App\Sites\Central\Http\Controllers\Account\GetStartedController;
use App\Sites\Central\Http\Requests\Dashboard\CreateOrganizationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class OrganizationController extends Controller
{
    /**
     * A new business, on the auth host. ?app=<code> preselects that
     * application's trial; ?intent=<id> is the account's registration intent
     * (a product chosen on the public site), applied once it is created.
     */
    public function create(Request $request, MarketingCatalog $catalog, RegistrationIntents $intents): Response
    {
        $intent = $this->intent($request, $request->query('intent'));
        $chosen = $intent === null ? null : $intents->describe($intent);

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
                'selected' => $intent === null && in_array($selected, array_column($trialable, 'code'), true) ? $selected : null,
            ],
            'intent' => $intent === null || $chosen === null ? null : [
                'id' => $intent->id,
                'name' => $chosen['name'],
                'action' => $intent->action,
                'plan' => $chosen['plan']?->name,
            ],
        ]);
    }

    public function store(CreateOrganizationRequest $request, CreateOrganization $create, AddFirstBranch $firstBranch, ManageEntitlements $entitlements, RegistrationIntents $intents): SymfonyResponse
    {
        $intent = $this->intent($request, $request->input('intent'));

        // The business and its first branch together, or neither.
        $organization = DB::transaction(function () use ($create, $firstBranch, $request): Organization {
            $organization = $create->handle($request->user(), $request->validated());
            $firstBranch->handle($organization, $request->user());

            return $organization;
        });

        foreach ($request->trialApplications() as $code) {
            try {
                $entitlements->startTrial($organization, Application::query()->where('code', $code)->sole(), $request->user());
            } catch (TrialRefused) {
                // Already on trial alongside an earlier choice (Finance
                // with Pharmacy): nothing more to start.
            }
        }

        // Created for a product chosen on the public site: start it there
        // (the owner is the one creating it), and open that product.
        if ($intent !== null) {
            return app(GetStartedController::class)->completeFor($intent, $request->user(), $organization->refresh(), $intents);
        }

        // The business has its own host and session: open it there (signing in
        // to it is silent, the auth host session is already there).
        return Inertia::location((string) $organization->refresh()->workspaceUrl());
    }

    /** The signed-in account's own open intent, or null. */
    private function intent(Request $request, mixed $id): ?RegistrationIntent
    {
        if (! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }

        return RegistrationIntent::query()->open()->whereKey($id)->where('user_id', $request->user()->getAuthIdentifier())->first();
    }

    public function slugAvailability(Request $request, CheckSlugAvailability $slugs): JsonResponse
    {
        $slug = (string) $request->query('slug', '');
        $errors = $slugs->errors($slug);

        return response()->json(['available' => $errors === [], 'errors' => $errors]);
    }
}
