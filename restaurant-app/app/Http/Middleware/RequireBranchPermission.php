<?php

namespace App\Http\Middleware;

use App\Support\CurrentBranch;
use Closure;
use Illuminate\Http\Request;
use Miles\PlatformClient\Access\CurrentAccess;
use Symfony\Component\HttpFoundation\Response;

/**
 * branch.can:<permission> (after "branch"): the member holds the permission
 * in the branch they are working in: through an organization-wide role,
 * a role for this branch, or as owner. A role for another branch does not
 * count. Several permissions (branch.can:a,b) mean any one of them.
 */
class RequireBranchPermission
{
    public function __construct(
        private readonly CurrentAccess $access,
        private readonly CurrentBranch $branch,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $access = $this->access->get();

        abort_unless(
            $access !== null && array_any($permissions, fn (string $permission) => $access->allowsIn($permission, branchId: $this->branch->id())),
            403,
            'You do not have this permission in this branch.',
        );

        return $next($request);
    }
}
