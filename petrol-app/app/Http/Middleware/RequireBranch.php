<?php

namespace App\Http\Middleware;

use App\Support\Branches;
use App\Support\CurrentBranch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages that work in one branch: without a chosen branch, the member picks
 * one first. When none is open to them, that page says why.
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
