<?php

namespace App\Http\Middleware;

use App\Models\Person;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts each member who opens the app in the workspace's team directory, so
 * they can be given tasks and log time; keeps their name and email as the
 * platform has them. Runs after the platform middleware has bound the
 * workspace.
 */
class RememberPerson
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $person = Person::query()->firstOrNew(['user_id' => $user->id]);
            if (! $person->exists || $person->name !== $user->name || $person->email !== $user->email) {
                $person->forceFill(['user_id' => $user->id, 'name' => $user->name, 'email' => $user->email])->save();
            }
        }

        return $next($request);
    }
}
