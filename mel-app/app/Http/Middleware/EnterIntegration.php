<?php

namespace App\Http\Middleware;

use App\Mel\Models\Settings;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Miles\PlatformClient\Api\NotVisible;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Api\PlatformUnavailable;
use Miles\PlatformClient\Http\Middleware\RequireIntegrationToken;
use Miles\PlatformClient\Tenancy\CurrentTenant;
use Miles\SharedModuleKit\EventToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * After platform.integration:projects (ADR-0019 §20): Projects reads M&E
 * for one organization, the one the platform signed the token for. Nothing
 * the caller sends can name another one.
 *
 * Nothing is written: an organization that has never opened M&E is not set
 * up here (App\Http\Middleware\SetUpMel does that on first use).
 *
 *   403 organization_inactive   404 not_entitled | not_provisioned
 *   503 platform_unavailable
 */
final class EnterIntegration
{
    public function __construct(
        private readonly PlatformApi $platform,
        private readonly CurrentTenant $tenant,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var EventToken $token */
        $token = $request->attributes->get(RequireIntegrationToken::TOKEN);

        try {
            $organization = $this->platform->organization($token->organizationId);
        } catch (NotVisible) {
            return $this->refuse(404, 'not_entitled');
        } catch (PlatformUnavailable) {
            return $this->refuse(503, 'platform_unavailable');
        }
        if ($organization->id !== $token->organizationId) {
            return $this->refuse(404, 'not_entitled');
        }
        if (! $organization->isActive()) {
            return $this->refuse(403, 'organization_inactive');
        }

        $this->tenant->set($organization);
        if (! Settings::query()->exists()) {
            return $this->refuse(404, 'not_provisioned');
        }

        return $next($request);
    }

    private function refuse(int $status, string $error): JsonResponse
    {
        return response()->json(['error' => $error], $status);
    }
}
