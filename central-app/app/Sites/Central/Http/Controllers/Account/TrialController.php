<?php

namespace App\Sites\Central\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Modules\Marketing\Support\MarketingCatalog;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Memberships\Support\MembershipLookup;
use App\Sites\Central\Http\Middleware\RememberTrialChoice;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Start free trial" from the public site, on the auth host. Someone
 * without a business is sent to create one with the trial preselected;
 * someone with businesses chooses which one it is for (the trial itself is
 * confirmed on that business's host, /manage/trial) or a new one.
 */
class TrialController extends Controller
{
    public function show(Request $request, MembershipLookup $memberships, MarketingCatalog $catalog): Response|RedirectResponse
    {
        $selected = $request->string('app')->toString();
        $trialable = $catalog->trialable();
        $selected = in_array($selected, array_column($trialable, 'code'), true) ? $selected : '';

        // Reached the trial: the choice no longer needs remembering.
        $request->session()->forget(RememberTrialChoice::SESSION_KEY);

        $own = $memberships->activeFor($request->user())->load('organization.primarySlug');

        if ($own->isEmpty()) {
            return redirect()->route('organizations.create', $selected === '' ? [] : ['app' => $selected]);
        }

        return Inertia::render('central/trial-workspace', [
            'trialDays' => (int) config('platform.marketing.trial_days'),
            'application' => collect($trialable)->firstWhere('code', $selected)['name'] ?? null,
            'selected' => $selected === '' ? null : $selected,
            'workspaces' => $own
                ->map(fn (OrganizationMembership $membership) => [
                    'id' => $membership->organization_id,
                    'name' => $membership->organization->display_name,
                    'address' => $membership->organization->primarySlug === null ? null : PlatformHosts::workspace($membership->organization->primarySlug->slug),
                    'trialUrl' => $membership->organization->status->resolvesWorkspace()
                        ? $membership->organization->workspaceUrl('/manage/trial'.($selected === '' ? '' : '?app='.$selected))
                        : null,
                    'isOwner' => $membership->organization->owner_membership_id === $membership->id,
                ])
                ->values()
                ->all(),
        ]);
    }
}
