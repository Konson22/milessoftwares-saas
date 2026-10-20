<?php

namespace App\Modules\Entitlements\Console;

use App\Modules\Entitlements\Actions\ManageEntitlements;
use App\Modules\Subscriptions\Models\Plan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Brings active subscriptions in step with their plans' features. Runs
 * daily as a safety net behind the job queued on every plan change.
 */
class SyncPlanFeatures extends Command
{
    protected $signature = 'platform:sync-plan-features {plan? : Plan code; all plans with active subscriptions when omitted}';

    protected $description = 'Apply plan feature changes to active subscriptions';

    public function handle(ManageEntitlements $entitlements): int
    {
        $plans = Plan::query()
            ->when($this->argument('plan'), fn ($query, $code) => $query->where('code', $code))
            ->whereIn('id', DB::table('subscription_items')->where('status', 'active')->select('plan_id'))
            ->get();

        foreach ($plans as $plan) {
            $changed = $entitlements->syncPlanFeatures($plan);
            $this->line("{$plan->code}: {$changed} organization(s) updated.");
        }

        return self::SUCCESS;
    }
}
