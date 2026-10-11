<?php

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Models\AuthenticationEvent;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Writes sign-in, sign-out, MFA and credential events to authentication_events.
 */
final class AuthenticationEventRecorder
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $event,
        ?Authenticatable $user = null,
        ?string $identifier = null,
        array $context = [],
        string $guard = 'web',
    ): AuthenticationEvent {
        $request = request();

        $record = new AuthenticationEvent;
        $record->forceFill([
            'user_id' => $user?->getAuthIdentifier(),
            'event' => $event,
            'guard' => $guard,
            'identifier_hash' => $identifier === null ? null : self::hashIdentifier($identifier),
            'host' => $request->getHost() ?: null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'context' => $context === [] ? null : $context,
            'occurred_at' => now(),
        ])->save();

        return $record;
    }

    /**
     * Keyed hash of a login identifier, so attempts against unknown accounts can
     * be correlated without storing the raw email address.
     */
    public static function hashIdentifier(string $identifier): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($identifier)), (string) config('app.key'));
    }
}
