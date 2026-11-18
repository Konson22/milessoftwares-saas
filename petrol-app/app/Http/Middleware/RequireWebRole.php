<?php

namespace App\Http\Middleware;

use App\Petrol\AttendantSignIn;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Miles\PlatformClient\Access\CurrentAccess;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attendants work from the mobile app only. A member whose only petrol role
 * is Attendant gets a page saying so on the web, whatever they open; any
 * other role (or owning the workspace) opens the web as usual. The app's
 * copies of the pages (AppPages) leave this out.
 */
class RequireWebRole
{
    public const MOBILE_ONLY = [AttendantSignIn::ROLE];

    public function __construct(private readonly CurrentAccess $access) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $access = $this->access->get();

        if ($access !== null && ! $access->isOwner) {
            $roles = $access->roleKeys();

            if ($roles !== [] && array_diff($roles, self::MOBILE_ONLY) === []) {
                return Inertia::render('mobile-only')->toResponse($request)->setStatusCode(Response::HTTP_FORBIDDEN);
            }
        }

        return $next($request);
    }
}
