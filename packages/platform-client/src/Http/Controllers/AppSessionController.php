<?php

namespace Miles\PlatformClient\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Miles\PlatformClient\Access\CurrentAccess;
use Miles\PlatformClient\Http\Middleware\RequirePlatformToken;
use Miles\PlatformClient\PlatformConfig;
use Miles\PlatformClient\SignIn\AppTokens;
use Miles\PlatformClient\SignIn\SignInFailed;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * POST <path>/api/session (behind platform.api): the mobile app, just signed
 * in, hands over its id token so the product knows the user's name and
 * email, as the browser callback does for the web. The id token must name
 * the same user as the access token. Answers who signed in, and where.
 */
final class AppSessionController
{
    public function __invoke(Request $request, AppTokens $tokens, PlatformConfig $config, CurrentTenant $tenant, CurrentAccess $access): JsonResponse
    {
        $validated = $request->validate(['id_token' => ['required', 'string', 'max:8192']]);

        try {
            $identity = $tokens->identity($validated['id_token']);
        } catch (SignInFailed) {
            return RequirePlatformToken::unauthorized('invalid_token', 'Sign in again.');
        }

        if ($identity->subject !== $tokens->subject((string) $request->bearerToken())) {
            return RequirePlatformToken::unauthorized('invalid_token', 'Sign in again.');
        }

        $model = $config->userModel();
        $user = $model::query()->find($identity->subject) ?? new $model;
        $user->forceFill([
            $user->getKeyName() => $identity->subject,
            'name' => $identity->name,
            'email' => $identity->email,
        ])->save();

        $organization = $tenant->require();

        return response()->json([
            'user' => ['id' => $identity->subject, 'name' => $identity->name, 'email' => $identity->email],
            'organization' => [
                'id' => $organization->id,
                'slug' => $organization->slug,
                'name' => $organization->displayName,
                'currency' => $organization->defaultCurrency,
                'timezone' => $organization->timezone,
            ],
            'is_owner' => $access->get()->isOwner ?? false,
        ]);
    }
}
