<?php

namespace App\Logbook;

use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Miles\PlatformClient\Access\CurrentAccess;
use Miles\PlatformClient\Api\Department;
use Miles\PlatformClient\Api\EffectiveAccess;
use Miles\PlatformClient\Api\NotVisible;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Api\PlatformUnavailable;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * Someone whose reach Access works out (ADR-0026 §2.3): the signed-in
 * member, or another one (a task's assignee, a notification's recipient)
 * read from Central's effective access for this organization. Owners hold
 * every permission. Nobody without Logbook access is a Member.
 *
 * Besides what they may do (can), a member has where: reach() says which
 * departments a permission covers, from the scope of the roles granting it
 * in Central. A whole-workspace role (or ownership) covers everything; a
 * department role that department; a branch role the departments of that
 * branch.
 */
final readonly class Member
{
    /**
     * @param  Closure(string): bool  $can
     * @param  Closure(string): (list<string>|null)  $reach
     */
    private function __construct(public string $id, private Closure $can, private Closure $reach) {}

    public static function current(): self
    {
        $access = app(CurrentAccess::class)->get();

        return new self(
            (string) Auth::id(),
            fn (string $permission) => Gate::allows($permission),
            // No platform access in this request (console, demo seeding): the
            // Gate alone decides, at workspace level.
            fn (string $permission) => $access === null ? null : self::departments($access, $permission),
        );
    }

    /**
     * The member as Central sees them now; null when they have no Logbook
     * access here.
     *
     * @throws PlatformUnavailable when Central cannot say (callers retry later or refuse)
     */
    public static function of(string $userId): ?self
    {
        if (Auth::id() === $userId && Auth::check()) {
            return self::current();
        }

        $organizationId = app(CurrentTenant::class)->require()->id;
        try {
            $access = app(PlatformApi::class)->effectiveAccess($organizationId, $userId);
        } catch (NotVisible) {
            return null;
        }
        if (! $access->allowed) {
            return null;
        }

        return new self($userId, fn (string $permission) => $access->has($permission), fn (string $permission) => self::departments($access, $permission));
    }

    public function can(string $permission): bool
    {
        return ($this->can)($permission);
    }

    /**
     * Department ids where the permission applies; null means the whole
     * workspace. Empty when the member does not hold it.
     *
     * @return list<string>|null
     */
    public function reach(string $permission): ?array
    {
        return $this->can($permission) ? ($this->reach)($permission) : [];
    }

    /** Whether the permission covers the whole workspace (not one department). */
    public function everywhere(string $permission): bool
    {
        return $this->can($permission) && $this->reach($permission) === null;
    }

    /**
     * @return list<string>|null
     */
    private static function departments(EffectiveAccess $access, string $permission): ?array
    {
        $departments = [];
        $branches = [];

        foreach ($access->scopesFor($permission) as $scope) {
            $type = $scope['scope_type'] ?? null;
            if ($type === 'organization') {
                return null;
            }
            if ($type === 'department' && isset($scope['department_id'])) {
                $departments[] = $scope['department_id'];
            }
            if ($type === 'branch' && isset($scope['branch_id'])) {
                $branches[] = $scope['branch_id'];
            }
        }

        if ($branches !== []) {
            try {
                $all = app(PlatformApi::class)->departments($access->organizationId);
            } catch (PlatformUnavailable) {
                // Unknown branch layout: the branch role reaches nothing rather than too much.
                $all = [];
            }
            foreach ($all as $department) {
                /** @var Department $department */
                if ($department->branchId !== null && in_array($department->branchId, $branches, true)) {
                    $departments[] = $department->id;
                }
            }
        }

        return array_values(array_unique($departments));
    }
}
