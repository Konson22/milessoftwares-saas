<?php

namespace App\Support\Tenancy;

use App\Modules\Organizations\Models\Organization;

/**
 * The organization the current request or job acts for.
 *
 * Only ever set from trusted sources: the resolved workspace host, a verified
 * token claim, or an explicit run() by server-side code. Never from request
 * input.
 */
final class TenantContext
{
    private ?Organization $organization = null;

    public function set(Organization $organization): void
    {
        $this->organization = $organization;
    }

    public function clear(): void
    {
        $this->organization = null;
    }

    public function has(): bool
    {
        return $this->organization !== null;
    }

    public function organization(): ?Organization
    {
        return $this->organization;
    }

    public function id(): ?string
    {
        return $this->organization?->getKey();
    }

    public function require(): Organization
    {
        return $this->organization ?? throw new MissingTenantContext;
    }

    /**
     * Run a callback as the given organization, restoring the previous
     * context afterwards even if the callback throws.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(Organization $organization, callable $callback): mixed
    {
        $previous = $this->organization;
        $this->organization = $organization;

        try {
            return $callback();
        } finally {
            $this->organization = $previous;
        }
    }
}
