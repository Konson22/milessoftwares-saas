<?php

namespace App\Support;

use Miles\PlatformClient\Api\Branch;
use RuntimeException;

/**
 * The branch this request works in (set by the branch middleware). Scoped:
 * reset between requests and jobs.
 */
class CurrentBranch
{
    private ?Branch $branch = null;

    public function set(Branch $branch): void
    {
        $this->branch = $branch;
    }

    public function get(): ?Branch
    {
        return $this->branch;
    }

    public function require(): Branch
    {
        return $this->branch ?? throw new RuntimeException('No branch chosen for this request.');
    }

    public function id(): string
    {
        return $this->require()->id;
    }
}
