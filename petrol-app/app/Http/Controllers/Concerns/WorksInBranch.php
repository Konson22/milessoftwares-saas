<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Station;
use App\Models\User;
use App\Support\CurrentBranch;
use Illuminate\Http\Request;
use Miles\PlatformClient\Access\CurrentAccess;

/**
 * For controllers behind the branch middleware.
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

    protected function station(): Station
    {
        $station = Station::query()->where('branch_id', $this->branchId())->where('active', true)->first();
        abort_if($station === null, 404, 'Set up this station first.');

        return $station;
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
