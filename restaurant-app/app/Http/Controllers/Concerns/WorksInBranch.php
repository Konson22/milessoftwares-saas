<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Support\CurrentBranch;
use Illuminate\Http\Request;
use Miles\PlatformClient\Access\CurrentAccess;

/**
 * For controllers behind the branch middleware: the signed-in member, and
 * what they may do in the branch they work in.
 */
trait WorksInBranch
{
    protected function branchId(): string
    {
        return app(CurrentBranch::class)->id();
    }

    /**
     * Records of another branch look unknown.
     */
    protected function ensureHere(string $branchId): void
    {
        abort_unless($branchId === $this->branchId(), 404);
    }

    protected function canHere(string $permission): bool
    {
        return app(CurrentAccess::class)->get()?->allowsIn($permission, branchId: $this->branchId()) ?? false;
    }

    protected function member(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
