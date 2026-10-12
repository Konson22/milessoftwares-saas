<?php

namespace App\Http\Middleware;

use App\Pharmacy\Branches;
use App\Pharmacy\CurrentBranch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages that work on one branch's stock or sales: without a chosen branch,
 * the member picks one first (or is refused when none is open to them).
 */
class RequireBranch
{
    public function __construct(
        private readonly Branches $branches,
        private readonly CurrentBranch $current,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $branch = $this->branches->current($request);

        if ($branch === null) {
            abort_if($this->branches->available() === [], 403, 'You have no pharmacy role in any active branch.');

            return redirect()->guest(route('branch.edit'));
        }

        $this->current->set($branch);

        return $next($request);
    }
}
