<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allows a member holding any of these catalog permissions, for actions two
 * permissions share: RequireAnyPermission::class.':finance.a,finance.b'.
 */
class RequireAnyPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        abort_unless(Gate::forUser($request->user())->any($permissions), 403);

        return $next($request);
    }
}
