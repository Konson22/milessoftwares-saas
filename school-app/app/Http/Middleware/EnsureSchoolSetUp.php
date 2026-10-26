<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * School pages need the school's category: until it is set up, they lead
 * home (which offers the setup).
 */
class EnsureSchoolSetUp
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Workspace::category() === null) {
            return to_route('home');
        }

        return $next($request);
    }
}
