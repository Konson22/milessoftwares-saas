<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Support\Hosts\PlatformHosts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Landing page on the auth host after an app or admin sign-out: the central
 * session is still active, so the user chooses whether to end it as well.
 * Signing out is a CSRF-protected POST to Fortify's logout route.
 */
class SignOutController
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if ($request->user() === null) {
            return redirect()->away(PlatformHosts::url(PlatformHosts::root()));
        }

        return Inertia::render('central/auth/sign-out', [
            'homeUrl' => PlatformHosts::url(PlatformHosts::root()),
            'dashboardUrl' => PlatformHosts::url(PlatformHosts::app()),
        ]);
    }
}
