<?php

namespace App\Sites\Central\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Subscriptions\Actions\PlanRequests;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\PlanRequest;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Models\SubscriptionItem;
use App\Sites\Central\Http\Controllers\Dashboard\Concerns\ResolvesOwnedOrganization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The owner's view of plans and subscriptions. Changes are requests: staff
 * on admin.<root> approve them, which assigns or cancels the plan.
 */
class SubscriptionController extends Controller
{
    use ResolvesOwnedOrganization;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PlanRequests $requests,
    ) {}

    public function show(Request $request): Response
    {
        $organization = $this->ownedOrganization($request, $this->tenant);
        $pendingCancellations = PlanRequest::query()->where('status', 'pending')->where('type', 'cancel')->pluck('id', 'subscription_id');

        return Inertia::render('central/organizations/subscription', [
            'organization' => ['displayName' => $organization->display_name],
            'subscriptions' => Subscription::query()
                ->with('items.plan:id,name,billing_interval,price_minor,currency')
                ->latest()
                ->get()
                ->map(fn (Subscription $subscription) => [
                    'id' => $subscription->id,
                    'status' => $subscription->status,
                    'live' => in_array($subscription->status, PlanRequests::LIVE_STATUSES, true),
                    'trialEndsAt' => $subscription->trial_ends_at?->toDateString(),
                    'startedAt' => $subscription->created_at?->toDateString(),
                    'periodEnd' => $subscription->current_period_end?->toDateString(),
                    'renewsAt' => $subscription->renews_at?->toDateString(),
                    'cancelledAt' => $subscription->cancelled_at?->toDateString(),
                    'cancellationRequestId' => $pendingCancellations[$subscription->id] ?? null,
                    'plans' => $subscription->items->map(fn (SubscriptionItem $item) => [
                        'name' => $item->plan->name,
                        'priceMinor' => $item->plan->price_minor,
                        'currency' => $item->plan->currency,
                        'interval' => $item->plan->billing_interval,
                        'quantity' => $item->quantity,
                        'status' => $item->status,
                    ])->all(),
                ])
                ->all(),
            'entitlements' => Entitlement::query()
                ->current()
                ->whereNull('feature_key')
                ->join('applications', 'applications.id', '=', 'entitlements.application_id')
                ->orderBy('applications.name')
                ->get(['entitlements.id', 'applications.name as application', 'entitlements.source', 'entitlements.valid_until'])
                ->map(fn (Entitlement $entitlement) => [
                    'id' => $entitlement->id,
                    'application' => $entitlement->getAttribute('application'),
                    'source' => $entitlement->source,
                    'validUntil' => $entitlement->valid_until?->toDateString(),
                ])
                ->all(),
            'requests' => $this->recentRequests(),
        ]);
    }

    /**
     * Public, active plans, marked with what the organization already has
     * or has asked for.
     */
    public function plans(Request $request): Response
    {
        $organization = $this->ownedOrganization($request, $this->tenant);

        $subscribed = SubscriptionItem::query()
            ->where('status', 'active')
            ->whereHas('subscription', fn ($query) => $query->whereIn('status', PlanRequests::LIVE_STATUSES))
            ->pluck('plan_id')
            ->all();
        $requested = PlanRequest::query()->where('status', 'pending')->where('type', 'subscribe')->pluck('id', 'plan_id');

        return Inertia::render('central/organizations/plans', [
            'organization' => ['displayName' => $organization->display_name],
            'plans' => Plan::query()
                ->where('status', 'active')
                ->where('is_public', true)
                ->with('applications:id,name')
                ->orderBy('price_minor')
                ->get()
                ->map(fn (Plan $plan) => [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'interval' => $plan->billing_interval,
                    'priceMinor' => $plan->price_minor,
                    'currency' => $plan->currency,
                    'trialDays' => $plan->trial_days,
                    'isBundle' => $plan->is_bundle,
                    'applications' => $plan->applications->pluck('name')->all(),
                    'subscribed' => in_array($plan->id, $subscribed, true),
                    'requestId' => $requested[$plan->id] ?? null,
                ])
                ->all(),
            'requests' => $this->recentRequests(),
            'salesEmail' => config('platform.billing.contact_email'),
        ]);
    }

    public function requestPlan(Request $request): RedirectResponse
    {
        $organization = $this->ownedOrganization($request, $this->tenant);
        $validated = $request->validate([
            'plan_id' => ['required', 'uuid', Rule::exists('plans', 'id')],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $plan = Plan::query()->findOrFail($request->string('plan_id')->toString());
        $this->requests->requestPlan($organization, $plan, $request->user(), $validated['note'] ?? null);

        return $this->done(__('Request sent. We will be in touch about :plan.', ['plan' => $plan->name]));
    }

    public function requestCancellation(Request $request, string $subscription): RedirectResponse
    {
        $organization = $this->ownedOrganization($request, $this->tenant);
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        // Tenant-scoped: another organization's subscription id 404s.
        $record = Subscription::query()->findOrFail($subscription);
        $this->requests->requestCancellation($organization, $record, $request->user(), $validated['note'] ?? null);

        return $this->done(__('Cancellation requested. Access continues until it is processed.'));
    }

    public function withdraw(Request $request, string $planRequest): RedirectResponse
    {
        $organization = $this->ownedOrganization($request, $this->tenant);

        $this->requests->withdraw($organization, PlanRequest::query()->findOrFail($planRequest), $request->user());

        return $this->done(__('Request withdrawn.'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentRequests(): array
    {
        return array_values(PlanRequest::query()
            ->with(['plan:id,name', 'subscription.items.plan:id,name', 'requester:id,name'])
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (PlanRequest $planRequest) => [
                'id' => $planRequest->id,
                'type' => $planRequest->type,
                'status' => $planRequest->status,
                'subject' => $planRequest->plan->name
                    ?? $planRequest->subscription?->items->map(fn (SubscriptionItem $item) => $item->plan->name)->join(', '),
                'note' => $planRequest->note,
                'decisionNote' => $planRequest->decision_note,
                'requestedBy' => $planRequest->requester?->name,
                'requestedAt' => $planRequest->created_at?->toDateString(),
                'decidedAt' => $planRequest->decided_at?->toDateString(),
            ])
            ->all());
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
