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
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, MembershipLookup $memberships, Launcher $launcher, AccessResolver $access, AccountStanding $standing, MarketingCatalog $catalog): Response
    {
        $current = $request->attributes->get('membership');
        $current = $current instanceof OrganizationMembership ? $current : null;
        $isOwner = $current !== null && $access->isOwner($current);

        return Inertia::render('central/dashboard', [
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
            // Runs inside the current organization's tenant context
            // (ResolveCurrentOrganization), so these queries are scoped to it.
            'current' => $current === null ? null : [
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
     * Every module in the catalog (HR, Payroll, accounting, ...) and where
     * the organization stands with it: installed, asked for, on a plan it
     * can ask for, sold on request, or not released yet.
     *
     * @return list<array{code: string, name: string, tagline: string|null, description: string|null, state: string}>
     */
    private function modules(MarketingCatalog $catalog): array
    {
        $installed = Entitlement::query()
            ->current()
            ->whereNull('feature_key')
            ->join('applications', 'applications.id', '=', 'entitlements.application_id')
            ->pluck('applications.code')
            ->all();
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

        return array_map(fn (array $product) => [
            'code' => $product['code'],
            'name' => $product['name'],
            'tagline' => $product['tagline'],
            'description' => $product['description'],
            'state' => match (true) {
                in_array($product['code'], $installed, true) => 'installed',
                in_array($product['code'], $requested, true) => 'requested',
                $product['status'] === 'planned' => 'coming_soon',
                in_array($product['code'], $onPlans, true) => 'available',
                default => 'on_request',
            },
        ], $catalog->products());
    }
}
