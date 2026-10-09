<?php

namespace App\Modules\Identity\Http\Responses;

use App\Support\Hosts\PlatformHosts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signing out of the auth host lands on the public site, which is another
 * origin: Inertia visits need a location response, not an XHR redirect.
 */
class LogoutResponse implements LogoutResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        return Inertia::location(PlatformHosts::url(PlatformHosts::root()));
    }
}
