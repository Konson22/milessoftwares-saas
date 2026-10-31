<?php

namespace App\Sites\Central\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remembers the application chosen on the public site ("Start free trial",
 * /trial?app=...) before signing in, registering or verifying the email
 * address gets in the way: those can end on the dashboard rather than back
 * on the trial, and the dashboard then carries on to it. Runs before the
 * auth middleware, so guests' choices are kept too.
 */
class RememberTrialChoice
{
    public const SESSION_KEY = 'trial.application';

    public function handle(Request $request, Closure $next): Response
    {
        $code = $request->string('app')->toString();

        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $code) === 1) {
            $request->session()->put(self::SESSION_KEY, $code);
        }

        return $next($request);
    }
}
