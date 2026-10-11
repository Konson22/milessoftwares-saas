<?php

namespace App\Modules\Identity\Support;

use App\Models\User;
use App\Modules\Identity\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Keeps a user_sessions row per signed-in browser session. The row id lives in
 * the session payload, so tracking works with any session driver and survives
 * session id regeneration.
 */
final class SessionTracker
{
    public const SESSION_KEY = 'identity.session_id';

    /**
     * last_seen_at is written at most this often per session.
     */
    private const TOUCH_INTERVAL_SECONDS = 60;

    public function start(User $user, Request $request): UserSession
    {
        $session = new UserSession;
        $session->forceFill([
            'user_id' => $user->getKey(),
            'host' => $request->getHost(),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            'created_at' => now(),
            'last_seen_at' => now(),
        ])->save();

        $request->session()->put(self::SESSION_KEY, $session->id);

        return $session;
    }

    public function current(Request $request): ?UserSession
    {
        $id = $request->session()->get(self::SESSION_KEY);

        return is_string($id) && Str::isUuid($id) ? UserSession::query()->find($id) : null;
    }

    public function touch(UserSession $session, Request $request): void
    {
        if ($session->last_seen_at->diffInSeconds(now()) < self::TOUCH_INTERVAL_SECONDS) {
            return;
        }

        $session->forceFill([
            'last_seen_at' => now(),
            'ip_address' => $request->ip(),
        ])->save();
    }

    /**
     * Sign-out already cycles the remember token itself; any other revocation
     * must rotate it so the revoked browser cannot sign straight back in.
     */
    public function revoke(UserSession $session, string $reason, bool $rotateRememberToken = true): void
    {
        if ($session->isRevoked()) {
            return;
        }

        $session->forceFill(['revoked_at' => now(), 'revoked_reason' => $reason])->save();

        if ($rotateRememberToken) {
            $this->rotateRememberToken($session->user);
        }
    }

    /**
     * Revoke every active session of the user except $keep. Remember-me tokens
     * are rotated too, otherwise a revoked browser would sign straight back in.
     */
    public function revokeAll(User $user, string $reason, ?UserSession $keep = null): int
    {
        $count = UserSession::query()
            ->active()
            ->where('user_id', $user->getKey())
            ->when($keep !== null, fn ($query) => $query->whereKeyNot($keep?->getKey()))
            ->update(['revoked_at' => now(), 'revoked_reason' => $reason]);

        $this->rotateRememberToken($user);

        return $count;
    }

    private function rotateRememberToken(User $user): void
    {
        $user->setRememberToken(Str::random(60));
        $user->timestamps = false;
        $user->save();
        $user->timestamps = true;
    }
}
