<?php

namespace App\Modules\Entitlements\Actions;

use App\Models\User;
use App\Modules\Administration\Models\PlatformStaff;
use App\Modules\Applications\Models\Application;
use App\Modules\Applications\Support\TenantRedirectUris;
use App\Modules\Audit\AuditLogger;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Integration\Support\IntegrationEvents;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Provisioning\Support\Provisioner;
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
        private readonly IntegrationEvents $events,
        private readonly Provisioner $provisioner,
    ) {}

    public function assignPlan(Organization $organization, Plan $plan, ?PlatformStaff $actor, bool $startTrial = false): Subscription
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

        $this->afterEntitlementChange($organization, $plan->applications->pluck('id')->all(), provision: true);
        $this->record('subscription.started', $subscription, $organization, $actor, ['plan' => $plan->code, 'trial_ends_at' => $trialEndsAt?->toIso8601String()]);

        return $subscription;
    }

    public function cancel(Organization $organization, Subscription $subscription, ?PlatformStaff $actor, string $reason): void
    {
        $this->tenant->run($organization, fn () => DB::transaction(function () use ($subscription): void {
            $subscription->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();

            $itemIds = $subscription->items()->pluck('id');
            SubscriptionItem::query()->whereKey($itemIds)->update(['status' => 'ended', 'ends_at' => now()]);
            Entitlement::query()->whereIn('subscription_item_id', $itemIds)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }));

        $this->afterEntitlementChange($organization, $this->tenant->run($organization, fn () => Entitlement::query()->whereIn('subscription_item_id', $subscription->items()->pluck('id'))->pluck('application_id')->unique()->all()));
        $this->record('subscription.cancelled', $subscription, $organization, $actor, ['reason' => $reason]);
    }

    public function grantManual(Organization $organization, Application $application, ?PlatformStaff $actor, string $reason, ?CarbonInterface $validUntil = null): Entitlement
    {
        $entitlement = $this->tenant->run($organization, fn () => $this->writeEntitlement($application->id, null, null, 'manual', $validUntil, null));

        $this->afterEntitlementChange($organization, [$application->id], provision: true);
        $this->record('entitlement.granted', $entitlement, $organization, $actor, [
            'application' => $application->code,
            'valid_until' => $validUntil?->toIso8601String(),
            'reason' => $reason,
        ]);

        return $entitlement;
    }

    /**
     * Self-serve: the owner of a new workspace tries one application free
     * for the configured number of days. One trial per application, ever,
     * so ending and restarting never extends it.
     */
    public function startTrial(Organization $organization, Application $application, User $owner): Entitlement
    {
        $days = (int) config('platform.marketing.trial_days');

        $entitlement = $this->tenant->run($organization, function () use ($application, $days): Entitlement {
            $tried = Entitlement::query()
                ->where('application_id', $application->id)
                ->where('source', 'trial')
                ->exists();

            if ($tried) {
                throw new RuntimeException("This organization has already tried {$application->name}.");
            }

            return $this->writeEntitlement($application->id, null, null, 'trial', now()->addDays($days), null);
        });

        $this->afterEntitlementChange($organization, [$application->id], provision: true);
        $this->audit->record('entitlement.trial_started', $entitlement, [
            'application' => $application->code,
            'valid_until' => $entitlement->valid_until?->toIso8601String(),
        ], actor: $owner, organizationId: $organization->id);

        return $entitlement;
    }

    public function revoke(Organization $organization, Entitlement $entitlement, ?PlatformStaff $actor, string $reason): void
    {
        $this->tenant->run($organization, fn () => $entitlement->forceFill(['revoked_at' => now()])->save());

        $this->afterEntitlementChange($organization, [$entitlement->application_id]);
        $this->record('entitlement.revoked', $entitlement, $organization, $actor, ['reason' => $reason]);
    }

    /**
     * Products are told, and per-tenant sign-in callbacks follow what the
     * organization may now use.
     *
     * @param  array<int, mixed>  $applicationIds
     */
    private function afterEntitlementChange(Organization $organization, array $applicationIds, bool $provision = false): void
    {
        TenantRedirectUris::sync($organization);

        foreach (array_unique(array_map('strval', $applicationIds)) as $applicationId) {
            $this->events->entitlementChanged($organization->id, $applicationId);

            if ($provision) {
                $application = Application::query()->find($applicationId);

                if ($application !== null) {
                    $this->provisioner->ensureTenant($organization, $application);
                }
            }
        }
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
    private function record(string $action, Subscription|Entitlement $subject, Organization $organization, ?PlatformStaff $actor, array $context): void
    {
        // Without a staff actor (console), the audit log records a system action.
        $this->audit->record($action, $subject, $context, actor: $actor?->user, organizationId: $organization->id, actorType: 'staff');
    }
}
