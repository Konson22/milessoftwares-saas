<?php

namespace App\Support;

use Miles\PlatformClient\Api\Branch;
use RuntimeException;

/**
 * The branch this request works in (set by the branch middleware from the
 * page's address, or by the API's from X-Branch-Id). Kept on the request
 * itself, so nothing outlives it: a management page served after a branch
 * page by the same worker has no branch.
 */
class CurrentBranch
{
    private const KEY = 'restaurant.branch';

    public function set(Branch $branch): void
    {
        request()->attributes->set(self::KEY, $branch);
    }

    public function get(): ?Branch
    {
        $branch = request()->attributes->get(self::KEY);

        return $branch instanceof Branch ? $branch : null;
    }

    public function require(): Branch
    {
        return $this->get() ?? throw new RuntimeException('No branch chosen for this request.');
    }

    public function id(): string
    {
        return $this->require()->id;
    }
}
