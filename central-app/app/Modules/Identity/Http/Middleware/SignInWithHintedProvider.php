<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Http\Controllers\GoogleSignInController;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Response;

/**
 * idp_hint=google on GET /oauth/authorize: an app that offers "Continue with
 * Google" on its own sign-in screen (the pharmacy desktop app) sends the
 * browser straight to Google instead of the sign-in page. The authorization
 * request then carries on as after any sign-in (url.intended): two-factor
 * authentication is still challenged, and the code goes to the client.
 *
 * Only for a request the OAuth server would take (a known client and one of
 * its exact redirect URIs); anything else is left to it to refuse. prompt=login
 * is honoured as Passport does: whoever is signed in here is signed out first.
 */
class SignInWithHintedProvider
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->query('idp_hint') !== 'google' || ! GoogleSignInController::configured() || ! $this->validClient($request)) {
            return $next($request);
        }

        $guard = Auth::guard(config('passport.guard'));
        $prompt = explode(' ', (string) $request->query('prompt', ''));
        $reprompt = in_array('login', $prompt, true) && ! $request->session()->get('promptedForLogin', false);

        if ($guard->check() && ! $reprompt) {
            return $next($request);
        }

        if ($guard->check()) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        // Back here once signed in, without being asked to sign in again.
        $request->session()->put('promptedForLogin', true);
        redirect()->setIntendedUrl($request->fullUrl());

        return redirect()->route('google.redirect');
    }

    private function validClient(Request $request): bool
    {
        $id = $request->query('client_id');
        $redirectUri = $request->query('redirect_uri');
        if (! is_string($id) || ! is_string($redirectUri)) {
            return false;
        }

        $client = Passport::client()->newQuery()->find($id);

        return $client !== null && ! $client->revoked && in_array($redirectUri, $client->redirect_uris, true);
    }
}
