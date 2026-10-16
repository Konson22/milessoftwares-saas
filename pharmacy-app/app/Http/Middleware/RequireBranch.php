<?php

namespace App\Http\Middleware;

use App\Support\Branches;
use App\Support\CurrentBranch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages that work on one branch's stock or sales: without a chosen branch,
 * the member picks one first. When none is open to them, the same page says
 * why (no branch yet, or no pharmacy role in one) instead of a bare error.
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
            return redirect()->guest(route('branch.edit'));
        }

        $this->current->set($branch);

        return $next($request);
    }
}
