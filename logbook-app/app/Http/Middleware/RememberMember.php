<?php

namespace App\Http\Middleware;

use App\Models\WorkspaceMember;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notes that the signed-in member uses Logbook in this organization, so they can
 * be chosen in people pickers. Runs after the platform middleware has
 * checked their access; at most one write per member per hour.
 */
class RememberMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->user()?->getAuthIdentifier();

        if (is_string($userId)) {
            $member = WorkspaceMember::query()->ofUser($userId)->first()
                ?? WorkspaceMember::query()->createOrFirst(['user_id' => $userId], ['last_seen_at' => now()]);

            if ($member->last_seen_at->lt(now()->subHour())) {
                $member->update(['last_seen_at' => now()]);
            }
        }

        return $next($request);
    }
}
