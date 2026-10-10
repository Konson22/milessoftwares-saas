<?php

namespace App\Modules\Provisioning\Support;

use App\Modules\Applications\Models\Application;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Provisioning\Jobs\ProvisionTenant;
use App\Modules\Provisioning\Models\ProvisioningRun;
use App\Modules\Provisioning\Models\ProvisioningStep;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Creates the tenant in a product when an organization starts using it.
 * Idempotent per organization and application: a tenant that was created
 * once is never created again, and a run in progress is not duplicated.
 */
final class Provisioner
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function ensureTenant(Organization $organization, Application $application): ?ProvisioningStep
    {
        if (! in_array($application->provisioning_method, ['sync_api', 'async_api'], true) || blank($application->integration_endpoint)) {
            return null;
        }

        return $this->tenant->run($organization, function () use ($organization, $application): ProvisioningStep {
            $key = "tenant.create:{$application->code}";

            $existing = ProvisioningStep::query()
                ->where('application_id', $application->id)
                ->where('step_key', $key)
                ->whereIn('status', ['pending', 'running', 'succeeded'])
                ->latest()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $step = DB::transaction(function () use ($organization, $application, $key): ProvisioningStep {
                $run = new ProvisioningRun;
                $run->forceFill([
                    'idempotency_key' => "{$key}:{$organization->id}:".now()->getTimestamp(),
                    'status' => 'running',
                    'started_at' => now(),
                ])->save();

                $step = new ProvisioningStep;
                $step->forceFill([
                    'provisioning_run_id' => $run->id,
                    'application_id' => $application->id,
                    'step_key' => $key,
                    'status' => 'pending',
                ])->save();

                return $step;
            });

            ProvisionTenant::dispatch($organization->id, $step->id)->afterCommit();

            return $step;
        });
    }

    /**
     * Staff retry from admin: a failed step goes back to pending.
     */
    public function retry(Organization $organization, ProvisioningStep $step): void
    {
        if ($step->status !== 'failed') {
            return;
        }

        $this->tenant->run($organization, function () use ($step): void {
            $step->forceFill(['status' => 'pending', 'attempts' => 0, 'last_error' => null])->save();
            $step->run->forceFill(['status' => 'running', 'finished_at' => null, 'last_error' => null])->save();
        });

        ProvisionTenant::dispatch($organization->id, $step->id);
    }

    /**
     * Marks a step finished and closes the run once every step is settled.
     */
    public static function finish(ProvisioningStep $step, bool $succeeded, ?string $error = null): void
    {
        $step->forceFill([
            'status' => $succeeded ? 'succeeded' : 'failed',
            'last_error' => $error,
            'completed_at' => $succeeded ? now() : null,
        ])->save();

        $run = $step->run;
        $statuses = $run->steps()->pluck('status');

        if ($statuses->contains(fn (string $status) => in_array($status, ['pending', 'running'], true))) {
            return;
        }

        $failed = $statuses->contains('failed');
        $run->forceFill([
            'status' => $failed ? 'failed' : 'succeeded',
            'finished_at' => now(),
            'last_error' => $failed ? $error : null,
        ])->save();
    }
}
