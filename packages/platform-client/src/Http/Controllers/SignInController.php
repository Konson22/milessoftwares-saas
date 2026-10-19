<?php

namespace Miles\PlatformClient\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Miles\PlatformClient\Api\NotVisible;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Api\PlatformUnavailable;
use Miles\PlatformClient\Http\Middleware\RequirePlatformAccess;
use Miles\PlatformClient\PlatformConfig;
use Miles\PlatformClient\SignIn\SignIn;
use Miles\PlatformClient\SignIn\SignInFailed;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * auth/login, auth/callback and logout on the workspace host, under the
 * product's path. The callback URL must be registered on the platform as the
 * tenant callback path, e.g. /pharmacy/auth/callback.
 */
final class SignInController
{
    public function __construct(
        private readonly SignIn $signIn,
        private readonly PlatformApi $api,
        private readonly PlatformConfig $config,
        private readonly CurrentTenant $tenant,
    ) {}

    public function login(Request $request): RedirectResponse
    {
        return redirect()->away($this->signIn->authorizationUrl($request, route($this->config->routeName('callback'))));
    }

    public function callback(Request $request): RedirectResponse
    {
        $tenant = $this->tenant->require();

        try {
            $identity = $this->signIn->complete($request);
            $access = $this->api->effectiveAccess($tenant->id, $identity->subject);
        } catch (SignInFailed $e) {
            Log::warning('Platform sign-in failed', ['organization' => $tenant->id, 'reason' => $e->getMessage()]);
            abort(403, 'Sign-in failed. Please try again.');
        } catch (NotVisible) {
            abort(403, 'You do not have access to this application.');
        } catch (PlatformUnavailable $e) {
            Log::error('Platform unavailable during sign-in', ['organization' => $tenant->id, 'error' => $e->getMessage()]);
            abort(503);
        }

        // Signed in on the platform, but not allowed here: no local session.
        abort_unless($access->allowed, 403, 'You do not have access to this application.');

        $model = $this->config->userModel();
        $user = $model::query()->find($identity->subject) ?? new $model;
        $user->forceFill([
            $user->getKeyName() => $identity->subject,
            'name' => $identity->name,
            'email' => $identity->email,
        ])->save();

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put(RequirePlatformAccess::SESSION_TENANT, $tenant->id);

        return redirect()->intended(url($this->config->home()));
    }

    /**
     * Ends the product session only; the platform session (and other
     * products) stay signed in. Back to the workspace home.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->away($request->getSchemeAndHttpHost().'/');
    }
}
