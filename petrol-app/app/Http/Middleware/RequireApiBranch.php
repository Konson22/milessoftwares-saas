<?php

namespace App\Http\Middleware;

use App\Support\Branches;
use App\Support\CurrentBranch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * branch.api: the API's counterpart of "branch". A device has no session,
 * so every call names the branch (X-Branch-Id). It must be one open to
 * the member, exactly as on the web.
 */
class RequireApiBranch
{
    public const HEADER = 'X-Branch-Id';

    public function __construct(
        private readonly Branches $branches,
        private readonly CurrentBranch $current,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) $request->header(self::HEADER, '');
        $branch = $id === '' ? null : $this->branches->find($id);

        if ($branch === null) {
            return response()->json(['message' => 'Choose a branch to work in.', 'code' => 'branch_required'], 409);
        }

        $this->current->set($branch);

        return $next($request);
    }
}
