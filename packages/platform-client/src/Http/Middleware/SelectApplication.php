<?php

namespace Miles\PlatformClient\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Miles\PlatformClient\PlatformConfig;
use Symfony\Component\HttpFoundation\Response;

/**
 * platform.app:<code> (before platform.tenant): the request is for this
 * application of the deployment, so the organization lookup, access check,
 * sign-in and webhooks use its clients and secret.
 */
final class SelectApplication
{
    public function __construct(private readonly PlatformConfig $config) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $application): Response
    {
        return $this->config->runAs($application, fn () => $next($request));
    }
}
