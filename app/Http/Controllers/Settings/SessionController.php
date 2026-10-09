<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\UserSession;
use App\Modules\Identity\Support\AuthenticationEventRecorder;
use App\Modules\Identity\Support\DeviceLabel;
use App\Modules\Identity\Support\SessionTracker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SessionController extends Controller
{
    public function __construct(
        private readonly SessionTracker $sessions,
        private readonly AuthenticationEventRecorder $recorder,
    ) {}

    /**
     * Show the user's active sessions.
     */
    public function index(Request $request): Response
    {
        $currentId = $this->sessions->current($request)?->id;

        $sessions = UserSession::query()
            ->active()
            ->where('user_id', $request->user()->getKey())
            ->latest('last_seen_at')
            ->get()
            ->map(fn (UserSession $session) => [
                'id' => $session->id,
                'device' => DeviceLabel::from($session->user_agent),
                'host' => $session->host,
                'ip_address' => $session->ip_address,
                'is_current' => $session->id === $currentId,
                'created_at' => $session->created_at->toIso8601String(),
                'last_seen_at_diff' => $session->last_seen_at->diffForHumans(),
            ])
            ->sortByDesc('is_current')
            ->values()
            ->all();

        return Inertia::render('settings/sessions', ['sessions' => $sessions]);
    }

    /**
     * Sign out one other session. The current session signs out via logout.
     */
    public function destroy(Request $request, string $session): RedirectResponse
    {
        $target = UserSession::query()
            ->active()
            ->where('user_id', $request->user()->getKey())
            ->findOrFail($session);

        abort_if($target->id === $this->sessions->current($request)?->id, 422, 'Use sign out to end the current session.');

        $this->sessions->revoke($target, 'user_revoked');
        $this->recorder->record('session_revoked', $request->user(), context: ['session_id' => $target->id]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Session signed out.')]);

        return back();
    }

    /**
     * Sign out every session except this one.
     */
    public function destroyOthers(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        $count = $this->sessions->revokeAll($request->user(), 'user_revoked', keep: $this->sessions->current($request));
        $this->recorder->record('sessions_revoked', $request->user(), context: ['count' => $count]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Other sessions signed out.')]);

        return back();
    }
}
