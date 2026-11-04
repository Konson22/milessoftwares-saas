<?php

namespace App\Modules\Applications\Console;

use App\Modules\Applications\Models\Application;
use App\Modules\Applications\Models\ApplicationRole;
use App\Modules\Audit\AuditLogger;
use App\Modules\Authorization\Actions\AssignmentDenied;
use App\Modules\Authorization\Actions\AssignRole;
use App\Modules\Authorization\Models\RoleAssignment;
use App\Modules\Authorization\Support\Scope;
use App\Modules\Integration\Support\IntegrationEvents;
use App\Modules\Organizations\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * An assignment points at the role of the catalog version it was made under,
 * so publishing a catalog changes nothing for existing holders. This moves
 * them to the role with the same key in the current version: the old
 * assignment is revoked and a new one made, with the same scope, expiry and
 * grantor. What it does to each holder is the new catalog's definition of
 * that role, so run it with --dry-run first.
 *
 * Left alone and reported: roles the current version no longer has or no
 * longer allows at that scope, and moves a blocking separation-of-duties pair
 * refuses. Warning pairs are reported and moved: the member's duties are
 * theirs already, and the organization can see the warning and act on it.
 */
class MoveAssignmentsToCatalog extends Command
{
    protected $signature = 'platform:catalog-reassign
        {application : The application code}
        {--dry-run : Report what would happen and change nothing}';

    protected $description = 'Move live role assignments to the current catalog version';

    public function handle(TenantContext $tenant, AssignRole $assignments, AuditLogger $audit, IntegrationEvents $events): int
    {
        $application = Application::query()->where('code', (string) $this->argument('application'))->first();
        if ($application === null || $application->current_catalog_version_id === null) {
            $this->components->error('No such application, or it has no published catalog.');

            return self::FAILURE;
        }

        /** @var Collection<string, ApplicationRole> $roles */
        $roles = ApplicationRole::query()->where('catalog_version_id', $application->current_catalog_version_id)->get()->keyBy('key');
        $dryRun = (bool) $this->option('dry-run');
        $moved = 0;
        $left = 0;

        foreach (Organization::query()->orderBy('id')->get() as $organization) {
            $tenant->run($organization, function () use ($application, $roles, $dryRun, $assignments, $audit, $events, $organization, &$moved, &$left) {
                $stale = RoleAssignment::query()->live()
                    ->where('application_id', $application->id)
                    ->whereNotIn('application_role_id', $roles->modelKeys())
                    ->with(['role', 'membership'])
                    ->get();

                foreach ($stale as $assignment) {
                    $label = "{$organization->id} {$assignment->membership_id} {$assignment->role->key}";
                    $role = $roles->get($assignment->role->key);
                    $scope = Scope::of($assignment);

                    if ($role === null || ! $role->allowsScope($scope->type)) {
                        $this->components->warn("{$label}: left as it is, the current catalog has no such role at this scope.");
                        $left++;

                        continue;
                    }

                    try {
                        foreach ($assignments->separationOfDutiesWarnings($role, $scope, $assignment->membership) as $warning) {
                            $this->components->warn("{$label}: {$warning}");
                        }
                    } catch (AssignmentDenied $denied) {
                        $this->components->warn("{$label}: left as it is. {$denied->getMessage()}");
                        $left++;

                        continue;
                    }

                    $moved++;
                    $this->line("{$label}: ".($dryRun ? 'would move' : 'moved'));
                    if (! $dryRun) {
                        $this->move($assignment, $role, $scope, $audit);
                        $events->accessChanged($organization->id, $application->id, $assignment->membership_id, $assignment->membership->user_id);
                    }
                }
            });
        }

        $this->components->info(($dryRun ? 'Would move' : 'Moved')." {$moved} assignment(s); left {$left}.");

        return self::SUCCESS;
    }

    private function move(RoleAssignment $assignment, ApplicationRole $role, Scope $scope, AuditLogger $audit): void
    {
        DB::transaction(function () use ($assignment, $role, $scope, $audit) {
            $assignment->forceFill(['revoked_at' => now()])->save();

            // They may already have been given the new role by hand.
            $existing = RoleAssignment::query()->live()
                ->where('membership_id', $assignment->membership_id)
                ->where('application_role_id', $role->id)
                ->where($scope->attributes())
                ->first();

            $replacement = $existing ?? tap(new RoleAssignment, fn (RoleAssignment $new) => $new->forceFill([
                'membership_id' => $assignment->membership_id,
                'application_id' => $assignment->application_id,
                'application_role_id' => $role->id,
                ...$scope->attributes(),
                'granted_by_membership_id' => $assignment->granted_by_membership_id,
                'expires_at' => $assignment->expires_at,
            ])->save());

            $audit->record('role.moved_to_catalog', $replacement, [
                'membership_id' => $assignment->membership_id,
                'role' => $role->key,
                'from_assignment_id' => $assignment->id,
                'from_role_id' => $assignment->application_role_id,
                'to_role_id' => $role->id,
                'catalog_version_id' => $role->catalog_version_id,
                'scope' => $scope->attributes(),
            ]);
        });
    }
}
