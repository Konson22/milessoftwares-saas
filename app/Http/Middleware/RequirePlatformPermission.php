<?php

namespace App\Http\Middleware;

use App\Modules\Administration\Enums\PlatformPermission;
use App\Modules\Administration\Models\PlatformStaff;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('staff.can:organizations.view'). Runs after
 * EnsurePlatformStaff.
 */
class RequirePlatformPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $staff = $request->attributes->get('staff');

        abort_unless(
            $staff instanceof PlatformStaff && $staff->hasPermission(PlatformPermission::from($permission)),
            403,
        );

        return $next($request);
    }
}
