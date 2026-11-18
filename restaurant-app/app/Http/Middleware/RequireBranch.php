<?php

namespace App\Http\Middleware;

use App\Support\Branches;
use App\Support\CurrentBranch;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages of one branch (its floor, kitchen, bills and stock), at
 * branches/{branch}/...: the branch is the one in the address, checked
 * against those open to the member on every request; any other is unknown
 * (404). Nothing shared decides it, so each browser tab keeps its own
 * branch, through refreshes too.
 *
 * Links and redirects made while serving the page stay in that branch
 * (URL::defaults); the controllers never see the parameter.
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
        $route = $request->route();
        $id = $route instanceof Route ? $route->parameter('branch') : null;
        $branch = is_string($id) && $id !== '' ? $this->branches->find($id) : null;
        abort_if($branch === null || ! $route instanceof Route, 404);

        $this->current->set($branch);
        URL::defaults(['branch' => $branch->id]);
        $route->forgetParameter('branch');
        $this->branches->remember($request, $branch);

        return $next($request);
    }
}
