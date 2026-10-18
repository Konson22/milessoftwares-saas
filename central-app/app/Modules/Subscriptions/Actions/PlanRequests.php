<?php

namespace App\Modules\Subscriptions\Actions;

use App\Models\User;
use App\Modules\Administration\Models\PlatformStaff;
use App\Modules\Audit\AuditLogger;
use App\Modules\Entitlements\Actions\ManageEntitlements;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\PlanRequest;
use App\Modules\Subscriptions\Models\Subscription;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owners ask for a plan or to end a subscription; staff decide. Approving
 * goes through ManageEntitlements, so access follows exactly as when staff
 * assign or cancel a plan themselves.
 */
final class PlanRequests
{
    public const LIVE_STATUSES = ['trialing', 'active', 'past_due'];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
        private readonly ManageEntitlements $entitlements,
    ) {}

    public function requestPlan(Organization $organization, Plan $plan, User $owner, ?string $note): PlanRequest
    {
        if ($plan->status !== 'active' || ! $plan->is_public) {
            throw ValidationException::withMessages(['plan_id' => __('This plan is not available.')]);
        }

        $request = $this->tenant->run($organization, function () use ($plan, $owner, $note): PlanRequest {
            $subscribed = Subscription::query()
                ->whereIn('status', self::LIVE_STATUSES)
                ->whereHas('items', fn ($items) => $items->where('plan_id', $plan->id)->where('status', 'active'))
                ->exists();

            if ($subscribed) {
                throw ValidationException::withMessages(['plan_id' => __('You are already subscribed to :plan.', ['plan' => $plan->name])]);
            }

            return $this->create(['type' => 'subscribe', 'plan_id' => $plan->id], $owner, $note);
        });

        $this->audit->record('plan_request.created', $request, ['type' => 'subscribe', 'plan' => $plan->code], actor: $owner, organizationId: $organization->id);

        return $request;
    }

    public function requestCancellation(Organization $organization, Subscription $subscription, User $owner, ?string $note): PlanRequest
    {
        if (! in_array($subscription->status, self::LIVE_STATUSES, true)) {
            throw ValidationException::withMessages(['subscription' => __('This subscription has already ended.')]);
        }

        $request = $this->tenant->run($organization, fn () => $this->create(['type' => 'cancel', 'subscription_id' => $subscription->id], $owner, $note));

        $this->audit->record('plan_request.created', $request, ['type' => 'cancel', 'subscription' => $subscription->id], actor: $owner, organizationId: $organization->id);

        return $request;
    }

    public function withdraw(Organization $organization, PlanRequest $request, User $owner): void
    {
        $this->tenant->run($organization, fn () => DB::transaction(fn () => $this->pending($request)->forceFill([
            'status' => 'withdrawn',
            'decided_at' => now(),
        ])->save()));

        $this->audit->record('plan_request.withdrawn', $request, ['type' => $request->type], actor: $owner, organizationId: $organization->id);
    }

    /**
     * A subscribe request starts the plan (with its trial when staff choose
     * one); a cancel request ends the subscription.
     */
    public function approve(Organization $organization, PlanRequest $request, PlatformStaff $actor, bool $startTrial = false, ?string $note = null): void
    {
        $this->tenant->run($organization, fn () => DB::transaction(function () use ($organization, $request, $actor, $startTrial, $note): void {
            $locked = $this->pending($request);

            if ($locked->type === 'subscribe') {
                $plan = Plan::query()->with('applications')->findOrFail($locked->plan_id);

                if ($plan->status !== 'active') {
                    throw ValidationException::withMessages(['request' => __('The plan is no longer active. Decline the request instead.')]);
                }

                $this->entitlements->assignPlan($organization, $plan, $actor, $startTrial);
            } else {
                $subscription = Subscription::query()->findOrFail($locked->subscription_id);

                if (in_array($subscription->status, self::LIVE_STATUSES, true)) {
                    $this->entitlements->cancel($organization, $subscription, $actor, __('Requested by the customer.').($locked->note ? ' '.$locked->note : ''));
                }
            }

            $locked->forceFill([
                'status' => 'approved',
                'decided_at' => now(),
                'decided_by_user_id' => $actor->user_id,
                'decision_note' => $note,
            ])->save();
        }));

        $this->audit->record('plan_request.approved', $request, ['type' => $request->type, 'note' => $note], actor: $actor->user, organizationId: $organization->id, actorType: 'staff');
    }

    public function decline(Organization $organization, PlanRequest $request, PlatformStaff $actor, string $note): void
    {
        $this->tenant->run($organization, fn () => DB::transaction(fn () => $this->pending($request)->forceFill([
            'status' => 'declined',
            'decided_at' => now(),
            'decided_by_user_id' => $actor->user_id,
            'decision_note' => $note,
        ])->save()));

        $this->audit->record('plan_request.declined', $request, ['type' => $request->type, 'note' => $note], actor: $actor->user, organizationId: $organization->id, actorType: 'staff');
    }

    private function pending(PlanRequest $request): PlanRequest
    {
        $locked = PlanRequest::query()->lockForUpdate()->findOrFail($request->id);

        if ($locked->status !== 'pending') {
            throw ValidationException::withMessages(['request' => __('This request has already been decided.')]);
        }

        return $locked;
    }

    /**
     * @param  array{type: string, plan_id?: string, subscription_id?: string}  $target
     */
    private function create(array $target, User $owner, ?string $note): PlanRequest
    {
        $duplicate = ValidationException::withMessages(['request' => __('You already have a request waiting for this.')]);

        if (PlanRequest::query()->where('status', 'pending')->where($target)->exists()) {
            throw $duplicate;
        }

        try {
            // A savepoint, so losing a race to the unique index leaves any
            // surrounding transaction usable.
            return DB::transaction(function () use ($target, $owner, $note): PlanRequest {
                $request = new PlanRequest;
                $request->forceFill([...$target, 'status' => 'pending', 'note' => $note, 'requested_by_user_id' => $owner->id])->save();

                return $request;
            });
        } catch (UniqueConstraintViolationException) {
            throw $duplicate;
        }
    }
}
