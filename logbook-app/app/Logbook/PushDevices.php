<?php

namespace App\Logbook;

use App\Logbook\Models\PushDevice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * Phones registered for push (ADR-0027 §2.3).
 *
 * - A token belongs to one install. Registering it moves it to the person
 *   and workspace signed in now, wherever it was before, so a shared or
 *   handed-on phone never receives someone else's notices.
 * - A refreshed token for the same install replaces the old one.
 * - Signing out removes the phone; the provider saying a token is no
 *   longer registered revokes it.
 * - Rows are kept (revoked) for the record; only active ones are pushed to.
 */
final class PushDevices
{
    private const TOKEN = '/^(ExponentPushToken|ExpoPushToken)\[[A-Za-z0-9_\-]{10,200}\]$/';

    /**
     * @return Builder<PushDevice>
     */
    public static function active(string $userId): Builder
    {
        return PushDevice::query()->where('user_id', $userId)->whereNull('revoked_at');
    }

    public static function register(string $userId, string $token, string $installId, string $platform, ?string $appVersion): PushDevice
    {
        if (preg_match(self::TOKEN, $token) !== 1) {
            throw ValidationException::withMessages(['token' => 'This is not an Expo push token.']);
        }

        return DB::transaction(function () use ($userId, $token, $installId, $platform, $appVersion) {
            // The same install with an older token, here or in another workspace: replaced.
            PushDevice::query()->withoutGlobalScopes()->where('install_id', $installId)->where('token', '!=', $token)
                ->whereNull('revoked_at')->update(['revoked_at' => now(), 'revoked_reason' => 'replaced', 'updated_at' => now()]);

            $existing = PushDevice::query()->withoutGlobalScopes()->where('token', $token)->lockForUpdate()->first();
            if ($existing !== null && $existing->organization_id !== self::organization()) {
                // Registered in another workspace before: it now belongs here.
                $existing->delete();
                $existing = null;
            }
            $device = $existing ?? new PushDevice(['token' => $token]);
            $device->forceFill([
                'user_id' => $userId,
                'install_id' => $installId,
                'platform' => $platform,
                'app_version' => $appVersion,
                'last_seen_at' => now(),
                'failures' => 0,
                'revoked_at' => null,
                'revoked_reason' => null,
            ])->save();

            return $device;
        });
    }

    /** Signing out on the phone: its token stops receiving this person's notices. */
    public static function unregister(string $userId, string $installId): int
    {
        return PushDevice::query()->where('user_id', $userId)->where('install_id', $installId)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_reason' => 'signed_out', 'updated_at' => now()]);
    }

    public static function revoke(string $deviceId, string $reason): void
    {
        PushDevice::query()->whereKey($deviceId)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_reason' => $reason, 'updated_at' => now()]);
    }

    private static function organization(): string
    {
        return app(CurrentTenant::class)->require()->id;
    }
}
