<?php

namespace App\Console\Commands;

use App\Logbook\Deliveries;
use App\Logbook\Reminders;
use App\Logbook\Schedules;
use App\Logbook\Tasks;
use App\Logbook\Tracks;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Miles\PlatformClient\Api\NotVisible;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Api\PlatformUnavailable;
use Miles\PlatformClient\Tenancy\CurrentTenant;
use Throwable;

/**
 * Logbook's clock (ADR-0026), every minute from the scheduler: for each
 * workspace Central still shows Logbook for, it makes the next day's
 * recurring tasks and scheduled occurrences (catching up after an
 * outage), marks missed occurrences, sends due reminders, and retries
 * email and push deliveries. Every step is safe to run twice or at once
 * (unique keys and row locks); a workspace that fails is logged and the
 * others go on. Nothing depends on one process staying up: a missed
 * minute is caught up by the next.
 */
class RunOperations extends Command
{
    protected $signature = 'logbook:operations {--workspace= : One organization id only}';

    protected $description = 'Make recurring tasks and expected entries, settle missed ones and send due reminders';

    public function handle(PlatformApi $platform, CurrentTenant $tenant): int
    {
        $now = CarbonImmutable::now();
        // Every organization Logbook has seen: provisioned, or opened by a member.
        $ids = Workspace::query()->withoutGlobalScopes()->select('organization_id')
            ->union(WorkspaceMember::query()->withoutGlobalScopes()->select('organization_id'))
            ->pluck('organization_id')->unique()
            ->when($this->option('workspace'), fn ($c, $id) => $c->filter(fn ($org) => $org === $id))
            ->values();

        $failed = 0;
        foreach ($ids as $organizationId) {
            try {
                $organization = $platform->organization((string) $organizationId);
            } catch (NotVisible) {
                continue;
            } catch (PlatformUnavailable $e) {
                $this->warn("Central unavailable for {$organizationId}: {$e->getMessage()}");
                $failed++;

                continue;
            }

            try {
                $counts = $tenant->run($organization, fn () => [
                    'tasks' => Tasks::generate($now),
                    'occurrences' => Schedules::generate($now),
                    'missed' => Schedules::settle($now),
                    'reminders' => Reminders::sendDue($now),
                    // Email and push the queue has not sent yet, and those due a retry.
                    'deliveries' => Deliveries::sweep($now),
                    'receipts' => Deliveries::checkReceipts($now),
                    // Routes past their retention (ADR-0028).
                    'routes_removed' => Tracks::prune($now),
                ]);
                if ($this->output->isVerbose()) {
                    $this->line($organization->slug.': '.json_encode($counts));
                }
            } catch (Throwable $e) {
                report($e);
                $this->error("{$organization->slug}: {$e->getMessage()}");
                $failed++;
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
