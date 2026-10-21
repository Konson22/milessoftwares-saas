<?php

namespace App\Modules\Identity\Http\Responses;

use App\Support\Hosts\PlatformHosts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * A verified address lands on the app dashboard (another host), or on the
 * page that sent the visitor to verify first.
 */
class VerifyEmailResponse implements VerifyEmailResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        return redirect()->intended(PlatformHosts::url(PlatformHosts::app(), '/dashboard'));
    }
}
