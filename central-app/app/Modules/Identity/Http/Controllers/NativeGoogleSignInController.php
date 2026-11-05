<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Support\AuthenticationEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Fortify;
use Throwable;

/**
 * Sign in with Google from a native app (the mobile apps' Google button, no
 * browser). The app signs in with Google on the device and posts the Google
 * ID token here, in a web session of that sign-in alone, as it posts a
 * password to /login. The answer is Fortify's: {two_factor: false} when
 * signed in, {two_factor: true} when /two-factor-challenge comes next. The
 * app then asks /oauth/authorize for its code, as after a password.
 *
 * The token is checked by Google (tokeninfo): signature, expiry, issuer.
 * Here: it was issued for one of our client IDs, and the account rules are
 * the browser sign-in's (GoogleSignInController::resolveUser).
 */
class NativeGoogleSignInController
{
    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    public function __construct(
        private readonly GoogleSignInController $google,
        private readonly AuthenticationEventRecorder $recorder,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(self::configured(), 404);

        $request->validate(['id_token' => ['required', 'string', 'max:4096']]);

        $claims = $this->verify($request->string('id_token')->toString());

        $email = Str::lower(trim((string) ($claims['email'] ?? '')));
        $user = $this->google->resolveUser(
            (string) ($claims['sub'] ?? ''),
            $email,
            filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
            (string) (($claims['name'] ?? '') ?: $email),
        );

        if ($user === null || $user->status !== 'active') {
            $this->recorder->record('login_failed', $user, $email ?: null, ['provider' => 'google', 'native' => true]);

            throw $this->refused(__('We could not sign you in with that Google account.'));
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put([
                'login.id' => $user->getKey(),
                'login.remember' => false,
            ]);

            TwoFactorAuthenticationChallenged::dispatch($user);

            return response()->json(['two_factor' => true]);
        }

        Auth::guard(config('fortify.guard'))->login($user);
        $request->session()->regenerate();

        return response()->json(['two_factor' => false]);
    }

    /** Native sign-in needs only the client IDs, not the browser flow's secret. */
    public static function configured(): bool
    {
        return self::audiences() !== [];
    }

    /** @return list<string> The web client ID (the apps' webClientId) and any native client IDs. */
    private static function audiences(): array
    {
        return array_values(array_filter([
            (string) config('services.google.client_id'),
            ...array_map('trim', explode(',', (string) config('services.google.native_client_ids'))),
        ]));
    }

    /** @return array<string, mixed> */
    private function verify(string $idToken): array
    {
        try {
            $response = Http::timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $idToken]);
        } catch (Throwable $e) {
            Log::warning('Native Google sign-in: tokeninfo unreachable', ['exception' => $e->getMessage()]);

            throw $this->refused(__('Google sign-in failed. Please try again.'));
        }

        $claims = $response->successful() ? (array) $response->json() : [];

        $valid = $claims !== []
            && in_array($claims['aud'] ?? null, self::audiences(), true)
            && in_array($claims['iss'] ?? null, self::ISSUERS, true)
            && (int) ($claims['exp'] ?? 0) > now()->getTimestamp();

        if (! $valid) {
            Log::warning('Native Google sign-in: token refused', ['status' => $response->status(), 'aud' => $claims['aud'] ?? null]);

            throw $this->refused(__('Google sign-in failed. Please try again.'));
        }

        return $claims;
    }

    private function refused(string $message): ValidationException
    {
        return ValidationException::withMessages([Fortify::username() => $message]);
    }
}
