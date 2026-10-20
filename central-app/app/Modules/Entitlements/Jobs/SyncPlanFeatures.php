<?php

namespace App\Modules\Entitlements\Jobs;

use App\Modules\Entitlements\Actions\ManageEntitlements;
use App\Modules\Subscriptions\Models\Plan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Applies a plan's changed features to its active subscriptions. The sync
 * is idempotent, so retries and the daily platform:sync-plan-features run
 * finish whatever a failed attempt left undone.
 */
class SyncPlanFeatures implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(public readonly string $planId) {}

    public function uniqueId(): string
    {
        return $this->planId;
    }

    public function handle(ManageEntitlements $entitlements): void
    {
        $plan = Plan::query()->find($this->planId);

        if ($plan !== null) {
            $entitlements->syncPlanFeatures($plan);
        }
    }
}
