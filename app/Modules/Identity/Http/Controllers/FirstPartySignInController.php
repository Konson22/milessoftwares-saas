<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\RelyingParty\FirstPartyClient;
use App\Modules\Identity\RelyingParty\OidcRelyingParty;
use App\Modules\Identity\RelyingParty\SignInFailed;
use App\Modules\Identity\Support\AuthenticationEventRecorder;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Sign-in for app. and admin. through the auth host (OIDC code flow). Each
 * host keeps its own host-only session; single sign-on comes from the
 * session on auth.
 */
class FirstPartySignInController
{
    public function __construct(
        private readonly OidcRelyingParty $relyingParty,
        private readonly AuthenticationEventRecorder $recorder,
    ) {}

    public function login(Request $request, string $client): RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->intended('/');
        }

        return redirect()->away($this->relyingParty->authorizationUrl($this->clientFor($client, $request), $request));
    }

    public function callback(Request $request, string $client): HttpResponse
    {
        try {
            $firstParty = $this->clientFor($client, $request);
            $user = $this->relyingParty->completeSignIn($firstParty, $request);
        } catch (SignInFailed $e) {
            Log::warning('First-party sign-in failed', ['client' => $client, 'reason' => $e->getMessage()]);
            $this->recorder->record('sso_failed', context: ['client' => $client]);

            return Inertia::render('central/auth/sign-in-failed', [
                'retryUrl' => PlatformHosts::url($request->getHost(), 'auth/login'),
            ])->toResponse($request)->setStatusCode(400);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    /**
     * Ends this host's session, then offers to end the central session too.
     */
    public function logout(Request $request): HttpResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Inertia::location(PlatformHosts::url(PlatformHosts::auth(), 'signout'));
    }

    /**
     * Workspace hosts share one client; the callback is the current tenant host,
     * which ResolveTenant has already validated.
     */
    private function clientFor(string $client, Request $request): FirstPartyClient
    {
        return FirstPartyClient::for($client, $client === 'workspace' ? $request->getHost() : null);
    }
}
