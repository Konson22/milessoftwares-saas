<?php

namespace App\Modules\Entitlements\Actions;

use App\Modules\Administration\Models\PlatformStaff;
use App\Modules\Applications\Models\Application;
use App\Modules\Audit\AuditLogger;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Models\SubscriptionItem;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Staff-driven changes to what an organization may use. Access decisions
 * read entitlements only, so every path here ends in entitlement rows:
 * a manual subscription derives them from its plan, a manual grant writes
 * one directly. Ending either stops access immediately.
 */
final class ManageEntitlements
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    public function assignPlan(Organization $organization, Plan $plan, PlatformStaff $actor, bool $startTrial = false): Subscription
    {
        if ($plan->status !== 'active') {
            throw new RuntimeException('Only active plans can be assigned.');
        }

        $trialEndsAt = $startTrial && $plan->trial_days > 0 ? now()->addDays($plan->trial_days) : null;

        $subscription = $this->tenant->run($organization, fn () => DB::transaction(function () use ($plan, $trialEndsAt): Subscription {
            $subscription = new Subscription;
            $subscription->forceFill([
                'status' => $trialEndsAt === null ? 'active' : 'trialing',
                'source' => 'manual',
                'trial_ends_at' => $trialEndsAt,
                'current_period_start' => now(),
            ])->save();

            $item = new SubscriptionItem;
            $item->forceFill([
                'subscription_id' => $subscription->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => now(),
            ])->save();

            foreach ($plan->applications as $application) {
                $this->writeEntitlement($application->id, null, null, $trialEndsAt === null ? 'subscription' : 'trial', $trialEndsAt, $item->id);
            }

            foreach (DB::table('plan_features')->where('plan_id', $plan->id)->get() as $feature) {
                if ($feature->application_id !== null) {
                    $this->writeEntitlement((string) $feature->application_id, (string) $feature->feature_key, $feature->limit_value === null ? null : (int) $feature->limit_value, $trialEndsAt === null ? 'subscription' : 'trial', $trialEndsAt, $item->id);
                }
            }

            return $subscription;
        }));

        $this->record('subscription.started', $subscription, $organization, $actor, ['plan' => $plan->code, 'trial_ends_at' => $trialEndsAt?->toIso8601String()]);

        return $subscription;
    }

    public function cancel(Organization $organization, Subscription $subscription, PlatformStaff $actor, string $reason): void
    {
        $this->tenant->run($organization, fn () => DB::transaction(function () use ($subscription): void {
            $subscription->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();

            $itemIds = $subscription->items()->pluck('id');
            SubscriptionItem::query()->whereKey($itemIds)->update(['status' => 'ended', 'ends_at' => now()]);
            Entitlement::query()->whereIn('subscription_item_id', $itemIds)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }));

        $this->record('subscription.cancelled', $subscription, $organization, $actor, ['reason' => $reason]);
    }

    public function grantManual(Organization $organization, Application $application, PlatformStaff $actor, string $reason, ?CarbonInterface $validUntil = null): Entitlement
    {
        $entitlement = $this->tenant->run($organization, fn () => $this->writeEntitlement($application->id, null, null, 'manual', $validUntil, null));

        $this->record('entitlement.granted', $entitlement, $organization, $actor, [
            'application' => $application->code,
            'valid_until' => $validUntil?->toIso8601String(),
            'reason' => $reason,
        ]);

        return $entitlement;
    }

    public function revoke(Organization $organization, Entitlement $entitlement, PlatformStaff $actor, string $reason): void
    {
        $this->tenant->run($organization, fn () => $entitlement->forceFill(['revoked_at' => now()])->save());

        $this->record('entitlement.revoked', $entitlement, $organization, $actor, ['reason' => $reason]);
    }

    private function writeEntitlement(string $applicationId, ?string $featureKey, ?int $limit, string $source, ?CarbonInterface $validUntil, ?string $itemId): Entitlement
    {
        $entitlement = new Entitlement;
        $entitlement->forceFill([
            'application_id' => $applicationId,
            'feature_key' => $featureKey,
            'limit_value' => $limit,
            'source' => $source,
            'subscription_item_id' => $itemId,
            'valid_from' => now(),
            'valid_until' => $validUntil,
        ])->save();

        return $entitlement;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(string $action, Subscription|Entitlement $subject, Organization $organization, PlatformStaff $actor, array $context): void
    {
        $this->audit->record($action, $subject, $context, actor: $actor->user, organizationId: $organization->id, actorType: 'staff');
    }
}
