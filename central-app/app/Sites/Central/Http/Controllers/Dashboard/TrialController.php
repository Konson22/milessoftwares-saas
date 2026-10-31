<?php

namespace App\Sites\Central\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Modules\Applications\Models\Application;
use App\Modules\Entitlements\Actions\ManageEntitlements;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Entitlements\Support\TrialRefused;
use App\Modules\Marketing\Support\MarketingCatalog;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Memberships\Support\MembershipLookup;
use App\Modules\Organizations\Models\Organization;
use App\Sites\Central\Http\Controllers\Dashboard\Concerns\ResolvesOwnedOrganization;
use App\Sites\Central\Http\Middleware\RememberTrialChoice;
use App\Support\Hosts\PlatformHosts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Start your free trial" for a business that already exists: someone with
 * workspaces first chooses one (or a new one), then the owner sees which
 * applications it may try and confirms one (a POST; nothing starts from a
 * link). Someone without a business is sent to create one, where the same
 * trial is preselected.
 */
class TrialController extends Controller
{
    use ResolvesOwnedOrganization;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly MarketingCatalog $catalog,
    ) {}

    public function show(Request $request, MembershipLookup $memberships): Response|RedirectResponse
    {
        $selected = $request->string('app')->toString();
        // Reached the trial: the choice no longer needs remembering.
        $request->session()->forget(RememberTrialChoice::SESSION_KEY);
        $membership = $request->attributes->get('membership');
        $organization = $this->tenant->organization();

        if (! $membership instanceof OrganizationMembership || $organization === null) {
            return redirect()->route('organizations.create', $selected === '' ? [] : ['app' => $selected]);
        }

        // Someone who already has workspaces first says which one the trial
        // is for, or that it is for a new one.
        if (! $request->boolean('chosen')) {
            return Inertia::render('central/trial-workspace', [
                'trialDays' => (int) config('platform.marketing.trial_days'),
                'application' => collect($this->catalog->trialable())->firstWhere('code', $selected)['name'] ?? null,
                'selected' => $selected === '' ? null : $selected,
                'workspaces' => $memberships->activeFor($request->user())
                    ->load('organization.primarySlug')
                    ->map(fn (OrganizationMembership $own) => [
                        'id' => $own->organization_id,
                        'name' => $own->organization->display_name,
                        'address' => $own->organization->primarySlug === null ? null : PlatformHosts::workspace($own->organization->primarySlug->slug),
                        'isOwner' => $own->organization->owner_membership_id === $own->id,
                        'isCurrent' => $own->organization_id === $organization->getKey(),
                    ])
                    ->values()
                    ->all(),
            ]);
        }

        $isOwner = $organization->owner_membership_id === $membership->id;
        $applications = $this->applications($organization);

        return Inertia::render('central/trial', [
            'organization' => ['displayName' => $organization->display_name],
            'isOwner' => $isOwner,
            'trialDays' => (int) config('platform.marketing.trial_days'),
            'applications' => $isOwner ? $applications : [],
            'selected' => in_array($selected, array_column($applications, 'code'), true) ? $selected : null,
        ]);
    }

    public function store(Request $request, ManageEntitlements $entitlements): RedirectResponse
    {
        $organization = $this->ownedOrganization($request, $this->tenant);
        abort_unless($organization->status->resolvesWorkspace(), 403);

        $validated = $request->validate([
            'application' => ['required', 'string', Rule::in(array_column($this->catalog->trialable(), 'code'))],
        ]);

        $application = Application::query()->where('code', $validated['application'])->sole();

        try {
            $entitlements->startTrial($organization, $application, $request->user());
        } catch (TrialRefused $refused) {
            if ($refused->reason !== TrialRefused::RUNNING) {
                return back()->withErrors(['application' => $refused->getMessage()]);
            }

            // A repeated confirmation: the trial is already on.
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your :days-day :name trial is running. Open it from your dashboard.', [
            'days' => (int) config('platform.marketing.trial_days'),
            'name' => $application->name,
        ])]);

        return redirect()->route('dashboard');
    }

    /**
     * Applications open for trials, and where the organization stands with
     * each: free to try, trying now (until when), tried before, or already
     * theirs on a plan.
     *
     * @return list<array{code: string, name: string, tagline: string|null, state: string, endsAt: string|null, daysLeft: int|null}>
     */
    private function applications(Organization $organization): array
    {
        $trialable = $this->catalog->trialable();
        $ids = Application::query()->whereIn('code', array_column($trialable, 'code'))->pluck('id', 'code');

        // Runs in the organization's tenant context, so rows are its own.
        $rows = Entitlement::query()
            ->whereIn('application_id', $ids->values())
            ->whereNull('feature_key')
            ->get(['application_id', 'source', 'valid_from', 'valid_until', 'revoked_at']);

        return array_map(function (array $product) use ($ids, $rows) {
            $own = $rows->where('application_id', $ids[$product['code']] ?? null);
            $current = $own->filter(fn (Entitlement $row) => $row->revoked_at === null
                && ! $row->valid_from->isFuture()
                && ($row->valid_until === null || $row->valid_until->isFuture()));
            $runningTrial = $current->firstWhere('source', 'trial');

            $state = match (true) {
                $runningTrial !== null => 'trialing',
                $current->isNotEmpty() => 'active',
                $own->contains('source', 'trial') => 'used',
                default => 'eligible',
            };

            return [
                'code' => $product['code'],
                'name' => $product['name'],
                'tagline' => $product['tagline'] ?? $product['description'],
                'state' => $state,
                'endsAt' => $runningTrial?->valid_until?->toDateString(),
                'daysLeft' => $runningTrial?->valid_until === null ? null : max(0, (int) ceil(now()->diffInDays($runningTrial->valid_until))),
            ];
        }, $trialable);
    }
}
