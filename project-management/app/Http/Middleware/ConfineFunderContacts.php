<?php

namespace App\Http\Middleware;

use App\Models\FunderContact;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps someone who only acts for a funder to the donor portal and their own
 * notifications. A list of what they may open, not of what they may not, so a
 * page added later, or one that shows something to any member, stays closed to
 * them. Runs before route-model binding.
 */
final class ConfineFunderContacts
{
    private const ALLOWED = ['donor.', 'notifications.'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! FunderContact::isConfined($user)) {
            return $next($request);
        }

        $name = (string) $request->route()?->getName();
        if ($name === 'home') {
            return to_route('donor.index');
        }
        foreach (self::ALLOWED as $allowed) {
            if (str_starts_with($name, $allowed)) {
                return $next($request);
            }
        }

        abort(403);
    }
}
