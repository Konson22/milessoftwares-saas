<?php

namespace App\Sites\Central\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Marketing\Support\MarketingCatalog;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Memberships\Support\MembershipLookup;
use App\Sites\Central\Http\Middleware\RememberTrialChoice;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The account's businesses, on the auth host. Everything about one business
 * (its dashboard, settings, plans and billing) lives on that business's own
 * host; this page only lists them and starts new ones.
 *
 * continue() is where a sign-in with nowhere else to go lands: on to the
 * trial chosen on the public site, straight into the only business, or to
 * this list. ?to= carries a page inside the business (old app. links), kept
 * to the workspace's /manage area.
 */
class WorkspacesController extends Controller
{
    private const DESTINATION = '/^manage(\/[A-Za-z0-9_-]+)*$/';

    public function index(Request $request, MembershipLookup $memberships): Response
    {
        $to = $this->destination($request);

        return Inertia::render('central/workspaces', [
            'workspaces' => $this->workspaces($memberships, $request->user())
                ->map(fn (OrganizationMembership $membership) => [
                    'id' => $membership->organization_id,
                    'displayName' => $membership->organization->display_name,
                    'status' => $membership->organization->status->value,
                    'address' => parse_url((string) $membership->organization->workspaceUrl(), PHP_URL_HOST) ?: null,
                    'url' => $membership->organization->status->resolvesWorkspace()
                        ? $membership->organization->workspaceUrl('/'.$to)
                        : null,
                    'isOwner' => $membership->organization->owner_membership_id === $membership->id,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function continue(Request $request, MembershipLookup $memberships, MarketingCatalog $catalog): SymfonyResponse
    {
        // Signed up from "Start free trial" on the public site: carry on to it.
        $chosen = $request->session()->pull(RememberTrialChoice::SESSION_KEY);
        if (is_string($chosen) && in_array($chosen, array_column($catalog->trialable(), 'code'), true)) {
            return redirect()->route('trial', ['app' => $chosen]);
        }

        $workspaces = $this->workspaces($memberships, $request->user());
        $only = $workspaces->count() === 1 ? $workspaces->first() : null;

        if ($only instanceof OrganizationMembership && $only->organization->status->resolvesWorkspace()) {
            return Inertia::location((string) $only->organization->workspaceUrl('/'.$this->destination($request)));
        }

        if ($workspaces->isEmpty()) {
            return redirect()->route('organizations.create');
        }

        return redirect()->route('workspaces', array_filter(['to' => $this->destination($request)]));
    }

    /**
     * @return Collection<int, OrganizationMembership>
     */
    private function workspaces(MembershipLookup $memberships, User $user): Collection
    {
        return $memberships->activeFor($user)->load('organization.primarySlug');
    }

    private function destination(Request $request): string
    {
        $to = trim($request->string('to')->toString(), '/');

        return preg_match(self::DESTINATION, $to) === 1 ? $to : '';
    }
}
