<?php

namespace App\Console\Commands;

use App\Logbook\Access;
use App\Logbook\AuditTrail;
use App\Logbook\Logbooks;
use App\Logbook\Member;
use App\Logbook\Models\AccessGrant;
use App\Logbook\Models\Logbook;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Miles\PlatformClient\Api\NotVisible;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Api\PlatformUnavailable;
use Miles\PlatformClient\Tenancy\CurrentTenant;
use Throwable;

/**
 * Once, when logbooks started requiring members (2026-11-22): every open
 * logbook gets as members the people who reached it until then, so nobody
 * loses access on the day. Each person is added in one role, the first
 * their Logbook role allows in the logbook's department: contributor,
 * then reviewer, then viewer. Managers are not added: they reach their
 * departments' logbooks anyway. Logbooks already restricted keep their
 * list. Safe to run again: existing memberships are left alone.
 */
class MembersFromOpenLogbooks extends Command
{
    protected $signature = 'logbook:members-from-open-logbooks
        {--organization= : Only this organization id}
        {--dry-run : Show what would be added, change nothing}';

    protected $description = 'Add the people who reached open logbooks as their members (once, when membership became required)';

    public function handle(PlatformApi $platform, CurrentTenant $tenant): int
    {
        $organizations = Logbook::query()->withoutGlobalScopes()->where('restricted', false)
            ->when($this->option('organization'), fn ($q, $id) => $q->where('organization_id', $id))
            ->distinct()->pluck('organization_id');
        $dry = (bool) $this->option('dry-run');
        $added = 0;

        foreach ($organizations as $organizationId) {
            try {
                $organization = $platform->organization((string) $organizationId);
            } catch (NotVisible|PlatformUnavailable $e) {
                $this->warn("{$organizationId}: skipped, the platform could not say ({$e->getMessage()}).");

                continue;
            }

            try {
                $added += $tenant->run($organization, fn () => $this->organization($platform, (string) $organizationId, $organization->displayName, $dry));
            } catch (Throwable $e) {
                $this->error("{$organization->displayName}: {$e->getMessage()}");

                return self::FAILURE;
            }
        }

        $this->info(($dry ? 'Would add ' : 'Added ').$added.' '.($added === 1 ? 'membership.' : 'memberships.'));

        return self::SUCCESS;
    }

    private function organization(PlatformApi $platform, string $organizationId, string $name, bool $dry): int
    {
        $logbooks = Logbook::query()->where('restricted', false)->get();
        $subjects = array_column($platform->applicationMembers($organizationId), 'sub');
        // Only people who have opened Logbook exist here; the others are added by a manager later.
        $users = User::query()->whereIn('id', $subjects)->get();
        $added = 0;

        DB::transaction(function () use ($logbooks, $users, $dry, $name, &$added) {
            foreach ($users as $user) {
                $member = Member::of((string) $user->id);
                if ($member === null) {
                    continue;
                }

                foreach ($logbooks as $logbook) {
                    if (Access::managesLogbook($logbook, $member)) {
                        continue;
                    }
                    $role = $this->roleFor($member, $logbook);
                    if ($role === null || AccessGrant::query()->where('logbook_id', $logbook->id)->where('user_id', $user->id)->whereNull('log_sheet_id')->exists()) {
                        continue;
                    }

                    $this->line("{$name} · {$logbook->name}: {$user->name} as {$role}");
                    $added++;
                    if ($dry) {
                        continue;
                    }

                    AccessGrant::query()->create([
                        'logbook_id' => $logbook->id,
                        'user_id' => $user->id,
                        'role' => $role,
                        'granted_by' => $logbook->created_by,
                    ]);
                    AuditTrail::record('logbook.access_granted', $logbook, null, [
                        'member' => $user->name,
                        'role' => $role,
                        'reason' => 'Reached it before logbooks had members',
                    ]);
                }
            }
        });

        return $added;
    }

    private function roleFor(Member $member, Logbook $logbook): ?string
    {
        foreach (Logbooks::MEMBER_PERMISSIONS as $role => [$permission]) {
            $reach = $member->reach($permission);
            if ($member->can($permission) && ($reach === null || ($logbook->department_id !== null && in_array($logbook->department_id, $reach, true)))) {
                return $role;
            }
        }

        return null;
    }
}
