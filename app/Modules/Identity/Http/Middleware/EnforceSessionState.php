<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Models\User;
use App\Modules\Identity\Support\SessionTracker;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs on every web request with a signed-in user: signs out sessions that were
 * revoked elsewhere and accounts that are no longer active, and records
 * activity for the sessions list.
 */
class EnforceSessionState
{
    public function __construct(private readonly SessionTracker $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if ($user->status !== 'active') {
            $this->sessions->revokeAll($user, 'account_'.$user->status);

            return $this->signOut($request, __('This account is not active.'));
        }

        $session = $this->sessions->current($request);

        // Sessions that started before tracking existed, or through a path that
        // did not fire the Login event, are adopted rather than rejected.
        if ($session === null || $session->user_id !== $user->getKey()) {
            $this->sessions->start($user, $request);

            return $next($request);
        }

        if ($session->isRevoked()) {
            return $this->signOut($request, __('You were signed out of this session.'));
        }

        $this->sessions->touch($session, $request);

        return $next($request);
    }

    private function signOut(Request $request, string $message): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            abort(401, $message);
        }

        return redirect()->guest(route('login'))->with('status', $message);
    }
}
