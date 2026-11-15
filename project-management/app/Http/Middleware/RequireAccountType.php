<?php

namespace App\Http\Middleware;

use App\Models\FunderContact;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nothing opens until the organization says what it uses Projects as, funder
 * or implementer: its menu depends on it. Runs after EnterWorkspace. Someone
 * who only acts for a funder (the donor portal) is not held up by it.
 */
final class RequireAccountType
{
    private const CHOOSER = ['account-type.show', 'account-type.store'];

    public function __construct(private readonly CurrentOrganization $organizations) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $name = (string) $request->route()?->getName();
        if (in_array($name, self::CHOOSER, true) || ! $this->organizations->require()->needsAccountType()) {
            return $next($request);
        }

        $user = $request->user();
        if ($user instanceof User && FunderContact::isConfined($user)) {
            return $next($request);
        }

        abort_unless($request->isMethod('GET'), 409, 'Choose what your organization uses Projects as first.');

        return to_route('account-type.show');
    }
}
