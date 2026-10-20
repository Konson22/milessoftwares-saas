<?php

namespace App\Modules\Entitlements\Actions;

use App\Models\User;
use App\Modules\Administration\Models\PlatformStaff;
use App\Modules\Applications\Enums\ApplicationKind;
use App\Modules\Applications\Models\Application;
use App\Modules\Applications\Models\ApplicationFeature;
use App\Modules\Applications\Support\TenantRedirectUris;
use App\Modules\Audit\AuditLogger;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Integration\Support\IntegrationEvents;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Products\Models\ErpProduct;
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
        $entitlement = $this->tenant->run($organization, fn () => $this->writeEntitlement($application->id, null, null, 'manual', $validUntil, null, reason: $reason, createdBy: $actor?->user_id));

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
     * so ending and restarting never extends it. The shared modules its ERP
     * products require (Finance for Pharmacy) are tried alongside, unless
     * the organization already has or has tried them.
     */
    public function startTrial(Organization $organization, Application $application, User $owner): Entitlement
    {
        $days = (int) config('platform.marketing.trial_days');
        $companions = $this->sharedModulesRequiredWith($application);

        [$entitlement, $alongside] = $this->tenant->run($organization, function () use ($application, $companions, $days): array {
            $tried = Entitlement::query()
                ->where('application_id', $application->id)
                ->where('source', 'trial')
                ->exists();

            if ($tried) {
                throw new RuntimeException("This organization has already tried {$application->name}.");
            }

            $until = now()->addDays($days);
            $alongside = [];

            foreach ($companions as $companion) {
                $covered = Entitlement::query()
                    ->where('application_id', $companion->id)
                    ->whereNull('feature_key')
                    ->where(fn ($query) => $query->where('source', 'trial')->orWhere(fn ($current) => $current->current()))
                    ->exists();

                if (! $covered) {
                    $alongside[$companion->code] = $this->writeEntitlement($companion->id, null, null, 'trial', $until, null);
                }
            }

            return [$this->writeEntitlement($application->id, null, null, 'trial', $until, null), $alongside];
        });

        $this->afterEntitlementChange($organization, [$application->id, ...array_map(fn (Entitlement $e) => $e->application_id, array_values($alongside))], provision: true);

        foreach ([$application->code => $entitlement, ...$alongside] as $code => $trial) {
            $this->audit->record('entitlement.trial_started', $trial, [
                'application' => $code,
                'valid_until' => $trial->valid_until?->toIso8601String(),
            ] + ($trial === $entitlement ? [] : ['with' => $application->code]), actor: $owner, organizationId: $organization->id);
        }

        return $entitlement;
    }

    /**
     * Shared modules (Finance) that an ERP product built on this application
     * cannot work without. Nothing for a shared module itself.
     *
     * @return list<Application>
     */
    private function sharedModulesRequiredWith(Application $application): array
    {
        if ($application->kind === ApplicationKind::SharedModule) {
            return [];
        }

        return array_values(ErpProduct::query()
            ->whereHas('requiredApplications', fn ($query) => $query->whereKey($application->id))
            ->with('requiredApplications')
            ->get()
            ->flatMap(fn (ErpProduct $product) => $product->requiredApplications)
            ->filter(fn (Application $required) => $required->kind === ApplicationKind::SharedModule)
            ->unique('id')
            ->all());
    }

    public function revoke(Organization $organization, Entitlement $entitlement, ?PlatformStaff $actor, string $reason): void
    {
        $this->tenant->run($organization, fn () => $entitlement->forceFill(['revoked_at' => now()])->save());

        $this->afterEntitlementChange($organization, [$entitlement->application_id]);
        $this->record('entitlement.revoked', $entitlement, $organization, $actor, array_filter([
            'feature' => $entitlement->feature_key,
            'effect' => $entitlement->feature_key === null ? null : $entitlement->effect,
            'reason' => $reason,
        ], fn ($value) => $value !== null));
    }

    /**
     * Staff grant or deny one feature for the organization, whatever its
     * plans say (EffectiveFeatures). One override per feature is in effect:
     * a new one replaces the previous. Plan changes never touch overrides,
     * and removing one (revoke) falls back to the plans.
     */
    public function overrideFeature(Organization $organization, ApplicationFeature $feature, string $effect, ?int $limit, PlatformStaff $actor, string $reason, ?CarbonInterface $validUntil = null): Entitlement
    {
        if (! in_array($effect, [Entitlement::GRANT, Entitlement::DENY], true)) {
            throw new RuntimeException("Unknown effect [{$effect}].");
        }

        [$entitlement, $replaced] = $this->tenant->run($organization, fn () => DB::transaction(function () use ($feature, $effect, $limit, $actor, $reason, $validUntil): array {
            $previous = Entitlement::query()
                ->current()
                ->where('application_id', $feature->application_id)
                ->where('feature_key', $feature->key)
                ->where('source', 'manual')
                ->lockForUpdate()
                ->get();

            Entitlement::query()->whereKey($previous->modelKeys())->update(['revoked_at' => now()]);

            $entitlement = $this->writeEntitlement($feature->application_id, $feature->key, $effect === Entitlement::DENY ? null : $limit, 'manual', $validUntil, null, $effect, $reason, $actor->user_id);

            return [$entitlement, $previous->modelKeys()];
        }));

        $this->afterEntitlementChange($organization, [$feature->application_id]);
        $this->record($effect === Entitlement::DENY ? 'entitlement.feature_denied' : 'entitlement.feature_granted', $entitlement, $organization, $actor, array_filter([
            'feature' => $feature->key,
            'limit' => $entitlement->limit_value,
            'valid_until' => $validUntil?->toIso8601String(),
            'reason' => $reason,
            'replaced' => $replaced === [] ? null : $replaced,
        ], fn ($value) => $value !== null));

        return $entitlement;
    }

    /**
     * Brings every active subscription to the plan's current features: rows
     * for features the plan dropped (or whose limit changed) are revoked,
     * new ones written with the subscription's own source and end date.
     * Staff overrides are not touched. Safe to run again: an organization
     * already in step changes nothing and hears nothing.
     *
     * @return int organizations changed
     */
    public function syncPlanFeatures(Plan $plan): int
    {
        $desired = DB::table('plan_features')
            ->where('plan_id', $plan->id)
            ->whereNotNull('application_id')
            ->get()
            ->mapWithKeys(fn (object $feature) => [(string) $feature->feature_key => [
                'application_id' => (string) $feature->application_id,
                'limit' => $feature->limit_value === null ? null : (int) $feature->limit_value,
            ]])
            ->all();

        $items = DB::table('subscription_items')
            ->where('plan_id', $plan->id)
            ->where('status', 'active')
            ->get(['id', 'organization_id'])
            ->groupBy('organization_id');

        $changed = 0;

        foreach ($items as $organizationId => $organizationItems) {
            $organization = Organization::query()->find($organizationId);

            if ($organization === null) {
                continue;
            }

            $applicationIds = $this->tenant->run($organization, fn () => DB::transaction(
                fn () => $this->syncItemFeatures($organizationItems->pluck('id')->map(fn ($id) => (string) $id)->all(), $desired),
            ));

            if ($applicationIds !== []) {
                $this->afterEntitlementChange($organization, $applicationIds);
                $this->audit->record('subscription.features_synced', $plan, ['plan' => $plan->code, 'features' => array_keys($desired)], organizationId: $organization->id);
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * @param  list<string>  $itemIds
     * @param  array<string, array{application_id: string, limit: int|null}>  $desired
     * @return list<string> applications whose features changed
     */
    private function syncItemFeatures(array $itemIds, array $desired): array
    {
        $touched = [];

        foreach ($itemIds as $itemId) {
            // Serializes concurrent syncs of the same subscription.
            SubscriptionItem::query()->whereKey($itemId)->lockForUpdate()->first();

            // Feature rows follow the item's application rows: trial or paid, and until when.
            $access = Entitlement::query()->current()->where('subscription_item_id', $itemId)->whereNull('feature_key')->first();
            $rows = Entitlement::query()->current()->where('subscription_item_id', $itemId)->whereNotNull('feature_key')->get();
            $kept = [];

            foreach ($rows as $row) {
                $want = $desired[(string) $row->feature_key] ?? null;

                if ($access !== null && $want !== null && $want['application_id'] === $row->application_id && $want['limit'] === $row->limit_value && ! isset($kept[$row->feature_key])) {
                    $kept[(string) $row->feature_key] = true;

                    continue;
                }

                $row->forceFill(['revoked_at' => now()])->save();
                $touched[] = $row->application_id;
            }

            if ($access === null) {
                continue;
            }

            foreach ($desired as $key => $want) {
                if (! isset($kept[$key])) {
                    $this->writeEntitlement($want['application_id'], $key, $want['limit'], $access->source, $access->valid_until, $itemId);
                    $touched[] = $want['application_id'];
                }
            }
        }

        return array_values(array_unique($touched));
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

    private function writeEntitlement(string $applicationId, ?string $featureKey, ?int $limit, string $source, ?CarbonInterface $validUntil, ?string $itemId, string $effect = Entitlement::GRANT, ?string $reason = null, ?string $createdBy = null): Entitlement
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
            'effect' => $effect,
            'reason' => $reason,
            'created_by' => $createdBy,
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
