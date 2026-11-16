<?php

namespace App\Http\Middleware;

use App\Mel\Models\Settings;
use App\Platform\SetUpWorkspace;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Miles\PlatformClient\Tenancy\CurrentTenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * An organization opening M&E before the platform's provisioning call
 * reached it gets the same set-up on first use (App\Platform\SetUpWorkspace).
 * Runs after the platform middleware; afterwards it is one read per request.
 */
final class SetUpMel
{
    public function __construct(private readonly SetUpWorkspace $setUp) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Settings::query()->exists()) {
            DB::transaction(fn () => $this->setUp->provision(app(CurrentTenant::class)->require()));
        }

        return $next($request);
    }
}
