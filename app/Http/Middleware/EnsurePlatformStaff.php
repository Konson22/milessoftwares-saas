<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Administration\Models\PlatformStaff;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for admin.<root>: active platform staff with confirmed MFA only. The
 * staff record is attached to the request for permission checks.
 */
class EnsurePlatformStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $staff = PlatformStaff::query()->with('roles')->where('user_id', $user->getKey())->first();

        abort_if($staff === null || ! $staff->isActive(), 403);
        abort_if($user->two_factor_confirmed_at === null, 403, 'Multi-factor authentication is required for platform administration.');

        $request->attributes->set('staff', $staff);

        return $next($request);
    }
}
