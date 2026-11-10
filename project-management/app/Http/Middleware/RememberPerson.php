<?php

namespace App\Http\Middleware;

use App\Models\FunderContact;
use App\Models\Person;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts each member who opens an organization in its team directory, so
 * they can be given tasks and log time; keeps their name and email as
 * their account has them. Runs after ResolveOrganization has bound the
 * organization.
 */
class RememberPerson
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Someone who acts for a funder is not on the team.
        if ($user instanceof User && FunderContact::forUser($user) === null) {
            $person = Person::query()->firstOrNew(['user_id' => $user->id]);
            if (! $person->exists || $person->name !== $user->name || $person->email !== $user->email) {
                $person->forceFill(['user_id' => $user->id, 'name' => $user->name, 'email' => $user->email])->save();
            }
        }

        return $next($request);
    }
}
