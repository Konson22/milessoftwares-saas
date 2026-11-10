<?php

namespace App\Modules\Applications\Console;

use App\Modules\Applications\Models\Application;
use App\Modules\Applications\Models\ApplicationPermission;
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
use Illuminate\Support\Facades\DB;

/**
 * Ends the assignments of a role the current catalog no longer has.
 * platform:catalog-reassign leaves those alone, so their holders would keep
 * what the old version granted (Finance's Bookkeeper, retired in 1.10.0,
 * could still pay suppliers directly).
 *
 * Each holder and what the role gave them is listed. By default the
 * assignment is only revoked: nobody is given anything in its place. --map
 * names a role of the current catalog to give instead, an explicit choice,
 * checked against separation of duties like any assignment; a blocking pair
 * leaves the old assignment revoked and the member without the new one, and
 * says so. Every change is audited and announced to the products
 * (access.changed), which drops their cached access for that member. It can
 * be run again: what it already did is not done twice.
 *
 * To undo a run, the audit log holds each revoked assignment
 * (role.retired: from_assignment_id, scope, expiry, grantor).
 */
class RetireCatalogRole extends Command
{
    protected $signature = 'platform:catalog-retire-role
        {application : The application code}
        {role : The key of the retired role}
        {--map= : A role of the current catalog to give each holder instead (default: none)}
        {--dry-run : Report what would happen and change nothing}';

    protected $description = 'Revoke assignments of a role the current catalog no longer has, optionally giving another role instead';

    public function handle(TenantContext $tenant, AssignRole $assignments, AuditLogger $audit, IntegrationEvents $events): int
    {
        $application = Application::query()->where('code', (string) $this->argument('application'))->first();
        if ($application === null || $application->current_catalog_version_id === null) {
            $this->components->error('No such application, or it has no published catalog.');

            return self::FAILURE;
        }

        $key = (string) $this->argument('role');
        $current = ApplicationRole::query()->where('catalog_version_id', $application->current_catalog_version_id)->get()->keyBy('key');
        if ($current->has($key)) {
            $this->components->error("{$key} is still in the current catalog: use platform:catalog-reassign.");

            return self::FAILURE;
        }

        $map = $this->option('map');
        $replacement = null;
        if (is_string($map) && $map !== '') {
            $replacement = $current->get($map);
            if ($replacement === null) {
                $this->components->error("{$map} is not a role of the current catalog.");

                return self::FAILURE;
            }
        }

        $dryRun = (bool) $this->option('dry-run');
        $revoked = 0;
        $given = 0;
        $refused = 0;

        foreach (Organization::query()->orderBy('id')->get() as $organization) {
            $tenant->run($organization, function () use ($application, $key, $replacement, $dryRun, $assignments, $audit, $events, $organization, &$revoked, &$given, &$refused) {
                $held = RoleAssignment::query()->live()
                    ->where('application_id', $application->id)
                    ->whereHas('role', fn ($query) => $query->where('key', $key))
                    ->with(['role.permissions', 'membership'])
                    ->get();

                foreach ($held as $assignment) {
                    $label = "{$organization->id} {$assignment->membership_id} {$key}";
                    $permissions = array_values($assignment->role->permissions->map(fn (ApplicationPermission $permission): string => $permission->key)->sort()->all());
                    $scope = Scope::of($assignment);
                    $this->line("{$label}: held ".implode(', ', $permissions));

                    // Revoked first, so the retired role is not counted against
                    // its own replacement; a dry run rolls the whole step back.
                    DB::beginTransaction();
                    try {
                        $target = $this->retire($label, $assignment, $permissions, $scope, $replacement, $assignments, $dryRun ? null : $audit, $refused);
                    } catch (\Throwable $e) {
                        DB::rollBack();
                        throw $e;
                    }
                    $dryRun ? DB::rollBack() : DB::commit();

                    $revoked++;
                    $target !== null && $given++;
                    $this->line("{$label}: ".($dryRun ? 'would revoke' : 'revoked').($target !== null ? ", {$target->key} ".($dryRun ? 'would be given' : 'given') : ''));

                    if (! $dryRun) {
                        $events->accessChanged($organization->id, $application->id, $assignment->membership_id, $assignment->membership->user_id);
                    }
                }
            });
        }

        $left = $dryRun ? null : RoleAssignment::query()->withoutGlobalScopes()->live()
            ->where('application_id', $application->id)
            ->whereHas('role', fn ($query) => $query->where('key', $key))
            ->count();

        $this->components->info(($dryRun ? 'Would revoke' : 'Revoked')." {$revoked} assignment(s); ".($dryRun ? 'would give' : 'gave')." {$given} replacement(s); {$refused} refused by separation of duties.");
        if ($left !== null && $left > 0) {
            $this->components->error("{$left} assignment(s) of {$key} are still live.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Revokes one assignment and gives the replacement, if any and if
     * separation of duties allows it. Returns the role given. No audit (null)
     * on a dry run, which is rolled back: the chain keeps no gaps.
     *
     * @param  list<string>  $permissions
     */
    private function retire(string $label, RoleAssignment $assignment, array $permissions, Scope $scope, ?ApplicationRole $replacement, AssignRole $assignments, ?AuditLogger $audit, int &$refused): ?ApplicationRole
    {
        $assignment->forceFill(['revoked_at' => now()])->save();

        $target = $replacement !== null && $replacement->allowsScope($scope->type) ? $replacement : null;
        if ($replacement !== null && $target === null) {
            $this->components->warn("{$label}: {$replacement->key} is not allowed at this scope; revoked without a replacement.");
        }
        if ($target !== null) {
            try {
                foreach ($assignments->separationOfDutiesWarnings($target, $scope, $assignment->membership) as $warning) {
                    $this->components->warn("{$label}: {$warning}");
                }
            } catch (AssignmentDenied $denied) {
                $this->components->warn("{$label}: not given {$target->key}. {$denied->getMessage()}");
                $target = null;
                $refused++;
            }
        }

        $audit?->record('role.retired', $assignment, [
            'membership_id' => $assignment->membership_id,
            'role' => $assignment->role->key,
            'from_assignment_id' => $assignment->id,
            'from_role_id' => $assignment->application_role_id,
            'catalog_version_id' => $assignment->role->catalog_version_id,
            'permissions' => $permissions,
            'scope' => $scope->attributes(),
            'expires_at' => $assignment->expires_at?->toIso8601String(),
            'granted_by_membership_id' => $assignment->granted_by_membership_id,
            'replaced_by' => $target?->key,
        ]);

        if ($target === null) {
            return null;
        }

        $existing = RoleAssignment::query()->live()
            ->where('membership_id', $assignment->membership_id)
            ->where('application_role_id', $target->id)
            ->where($scope->attributes())
            ->first();

        $given = $existing ?? tap(new RoleAssignment, fn (RoleAssignment $new) => $new->forceFill([
            'membership_id' => $assignment->membership_id,
            'application_id' => $assignment->application_id,
            'application_role_id' => $target->id,
            ...$scope->attributes(),
            'granted_by_membership_id' => $assignment->granted_by_membership_id,
            'expires_at' => $assignment->expires_at,
        ])->save());

        $audit?->record('role.replaced_after_retirement', $given, [
            'membership_id' => $assignment->membership_id,
            'from_assignment_id' => $assignment->id,
            'from_role' => $assignment->role->key,
            'to_role' => $target->key,
            'to_role_id' => $target->id,
            'catalog_version_id' => $target->catalog_version_id,
            'scope' => $scope->attributes(),
        ]);

        return $target;
    }
}
