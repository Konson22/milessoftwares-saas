<?php

namespace App\Sites\Central\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Modules\Applications\Support\Launcher;
use App\Modules\Authorization\Support\AccessResolver;
use App\Modules\Authorization\Support\WorkspaceAbilities;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Support\AccountStanding;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Identity\Http\Controllers\FirstPartySignInController;
use App\Modules\Marketing\Support\MarketingCatalog;
use App\Modules\Memberships\Models\Invitation;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Memberships\Support\MembershipLookup;
use App\Modules\Structure\Models\Branch;
use App\Modules\Structure\Models\Department;
use App\Modules\Subscriptions\Actions\PlanRequests;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\PlanRequest;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Models\SubscriptionItem;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The workspace home ({business}.<root>/). Administrators get the dashboard:
 * the business at a glance, its applications and, for the owner, what it
 * owes. Everyone else gets a page to pick one of their applications, and
 * right after signing in goes straight into it when they have only one;
 * coming back later shows the picker.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, MembershipLookup $memberships, Launcher $launcher, AccessResolver $access, AccountStanding $standing, MarketingCatalog $catalog, TenantContext $tenant): Response|RedirectResponse
    {
        $organization = $tenant->require();
        $current = $request->attributes->get('membership');
        abort_unless($current instanceof OrganizationMembership, 403);

        if (! (new WorkspaceAbilities($access))->administers($current)) {
            $modules = $launcher->for($current);

            if (count($modules) === 1 && $request->session()->get(FirstPartySignInController::JUST_SIGNED_IN) === true) {
                return redirect()->away($modules[0]['href']);
            }

            return Inertia::render('central/workspace/apps', [
                'organization' => ['id' => $organization->id, 'displayName' => $organization->display_name],
                'modules' => $modules,
            ]);
        }

        $isOwner = $access->isOwner($current);

        return Inertia::render('central/workspace/dashboard', [
            // The member's other businesses, each on its own host.
            'organizations' => $memberships->activeFor($request->user())
                ->map(fn (OrganizationMembership $membership) => [
                    'id' => $membership->organization_id,
                    'displayName' => $membership->organization->display_name,
                    'status' => $membership->organization->status->value,
                    'workspaceUrl' => $membership->organization->workspaceUrl(),
                    'isOwner' => $membership->organization->owner_membership_id === $membership->id,
                    'isCurrent' => $membership->organization_id === $current?->organization_id,
                ])
                ->values()
                ->all(),
            // Runs inside the workspace's tenant context (ResolveTenant), so
            // these queries are scoped to it.
            'current' => [
                'displayName' => $current->organization->display_name,
                'workspaceUrl' => $current->organization->workspaceUrl(),
                'applications' => $launcher->for($current),
                'counts' => [
                    'members' => OrganizationMembership::query()->where('status', 'active')->count(),
                    'branches' => Branch::query()->where('status', 'active')->count(),
                    'departments' => Department::query()->where('status', 'active')->count(),
                    'pendingInvitations' => Invitation::query()->where('status', 'pending')->where('expires_at', '>', now())->count(),
                ],
                'isOwner' => $isOwner,
                'canManageAccess' => (new WorkspaceAbilities($access))->manageAccess($current),
                // Billing is the owner's: other members never see what is owed.
                'billing' => $isOwner ? [
                    ...$standing->summary(),
                    'recentInvoices' => Invoice::query()
                        ->latest('issued_at')
                        ->limit(4)
                        ->get()
                        ->map(fn (Invoice $invoice) => [
                            'id' => $invoice->id,
                            'number' => $invoice->number,
                            'status' => $invoice->isOverdue() ? 'overdue' : $invoice->status,
                            'currency' => $invoice->currency,
                            'totalMinor' => $invoice->total_minor,
                            'dueOn' => $invoice->due_on->toDateString(),
                        ])
                        ->all(),
                    'subscriptions' => $this->subscriptions(),
                    'trials' => $this->trials(),
                    'recentPayments' => Payment::query()
                        ->with('invoice:id,number')
                        ->latest('paid_on')
                        ->latest()
                        ->limit(5)
                        ->get()
                        ->map(fn (Payment $payment) => [
                            'id' => $payment->id,
                            'invoiceId' => $payment->invoice_id,
                            'invoiceNumber' => $payment->invoice->number,
                            'status' => $payment->status,
                            'method' => $payment->method,
                            'amountMinor' => $payment->amount_minor,
                            'currency' => $payment->currency,
                            'paidOn' => $payment->paid_on->toDateString(),
                        ])
                        ->all(),
                    'modules' => $this->modules($catalog),
                ] : null,
            ],
        ]);
    }

    /**
     * Live subscriptions and the plans on them, soonest renewal first.
     *
     * @return list<array{id: string, status: string, trialEndsAt: string|null, renewsAt: string|null, plans: list<array{name: string, priceMinor: int, currency: string, interval: string}>}>
     */
    private function subscriptions(): array
    {
        return array_values(Subscription::query()
            ->whereIn('status', PlanRequests::LIVE_STATUSES)
            ->with('items.plan:id,name,billing_interval,price_minor,currency')
            ->orderBy('renews_at')
            ->get()
            ->map(fn (Subscription $subscription) => [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'trialEndsAt' => $subscription->trial_ends_at?->toDateString(),
                'renewsAt' => $subscription->renews_at?->toDateString(),
                'plans' => array_values($subscription->items
                    ->where('status', 'active')
                    ->map(fn (SubscriptionItem $item) => [
                        'name' => $item->plan->name,
                        'priceMinor' => $item->plan->price_minor * $item->quantity,
                        'currency' => $item->plan->currency,
                        'interval' => $item->plan->billing_interval,
                    ])
                    ->all()),
            ])
            ->all());
    }

    /**
     * Free trials running now, soonest end first, with the days left counted
     * on the server.
     *
     * @return list<array{code: string, name: string, endsAt: string|null, daysLeft: int|null}>
     */
    private function trials(): array
    {
        return array_values(Entitlement::query()
            ->current()
            ->where('source', 'trial')
            ->whereNull('feature_key')
            ->join('applications', 'applications.id', '=', 'entitlements.application_id')
            ->orderBy('entitlements.valid_until')
            ->get(['applications.code', 'applications.name', 'entitlements.valid_until'])
            ->map(fn (Entitlement $trial) => [
                'code' => (string) $trial->getAttribute('code'),
                'name' => (string) $trial->getAttribute('name'),
                'endsAt' => $trial->valid_until?->toDateString(),
                'daysLeft' => $trial->valid_until === null ? null : max(0, (int) ceil(now()->diffInDays($trial->valid_until))),
            ])
            ->all());
    }

    /**
     * Products that work best alongside each product, most useful first.
     * Drives the "Recommended for you" ranking on the dashboard.
     *
     * @var array<string, list<string>>
     */
    private const PAIRS_WELL_WITH = [
        'pharmacy' => ['finance', 'inventory', 'hr', 'crm'],
        'retail' => ['inventory', 'finance', 'crm', 'hr'],
        'finance' => ['advanced_accounting', 'hr', 'inventory'],
        'inventory' => ['retail', 'finance'],
        'hr' => ['finance'],
        'advanced_accounting' => ['finance'],
        'crm' => ['finance', 'retail'],
        'school' => ['finance', 'hr'],
        'clinic' => ['pharmacy', 'finance', 'hr'],
        'restaurant' => ['inventory', 'finance', 'retail', 'hr'],
    ];

    /**
     * Every module in the catalog (HR, Payroll, accounting, ...) and where
     * the organization stands with it: installed, asked for, on a plan it
     * can ask for, sold on request, or not released yet. Modules that pair
     * with an installed one name it in pairsWith and carry a lower rank
     * (null when nothing installed points to them).
     *
     * @return list<array{code: string, name: string, tagline: string|null, description: string|null, state: string, pairsWith: string|null, rank: int|null}>
     */
    private function modules(MarketingCatalog $catalog): array
    {
        $installed = Entitlement::query()
            ->current()
            ->whereNull('feature_key')
            ->join('applications', 'applications.id', '=', 'entitlements.application_id')
            ->pluck('applications.code')
            ->all();
        $trialing = Entitlement::query()
            ->current()
            ->whereNull('feature_key')
            ->where('source', 'trial')
            ->join('applications', 'applications.id', '=', 'entitlements.application_id')
            ->pluck('applications.code')
            ->all();
        // One trial per application, ever (ManageEntitlements::startTrial).
        $tried = Entitlement::query()
            ->where('source', 'trial')
            ->join('applications', 'applications.id', '=', 'entitlements.application_id')
            ->pluck('applications.code')
            ->all();
        $trialable = array_diff(array_column($catalog->trialable(), 'code'), $tried);
        $requested = PlanRequest::query()
            ->where('status', 'pending')
            ->where('type', 'subscribe')
            ->with('plan.applications:id,code')
            ->get()
            ->flatMap(fn (PlanRequest $planRequest) => $planRequest->plan?->applications->pluck('code') ?? [])
            ->all();
        $onPlans = Plan::query()
            ->where('status', 'active')
            ->where('is_public', true)
            ->with('applications:id,code')
            ->get()
            ->flatMap(fn (Plan $plan) => $plan->applications->pluck('code'))
            ->all();

        $products = $catalog->products();
        $names = array_column($products, 'name', 'code');

        return array_map(function (array $product) use ($installed, $trialing, $trialable, $requested, $onPlans, $names) {
            [$pairsWith, $rank] = $this->pairing($product['code'], $installed);

            return [
                'code' => $product['code'],
                'name' => $product['name'],
                'tagline' => $product['tagline'],
                'description' => $product['description'],
                'state' => match (true) {
                    in_array($product['code'], $trialing, true) => 'trialing',
                    in_array($product['code'], $installed, true) => 'installed',
                    in_array($product['code'], $requested, true) => 'requested',
                    $product['status'] === 'planned' => 'coming_soon',
                    in_array($product['code'], $trialable, true) => 'trialable',
                    in_array($product['code'], $onPlans, true) => 'available',
                    default => 'on_request',
                },
                'pairsWith' => $pairsWith === null ? null : ($names[$pairsWith] ?? null),
                'rank' => $rank,
            ];
        }, $products);
    }

    /**
     * The installed product this one pairs best with, and how closely (its
     * position in that product's list; lower is closer).
     *
     * @param  array<mixed>  $installed  application codes
     * @return array{0: string|null, 1: int|null}
     */
    private function pairing(string $code, array $installed): array
    {
        $best = [null, null];

        foreach ($installed as $installedCode) {
            if (! is_string($installedCode)) {
                continue;
            }

            $position = array_search($code, self::PAIRS_WELL_WITH[$installedCode] ?? [], true);

            if ($position !== false && ($best[1] === null || $position < $best[1])) {
                $best = [$installedCode, $position];
            }
        }

        return $best;
    }
}
