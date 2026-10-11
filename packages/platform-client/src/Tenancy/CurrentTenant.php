<?php

namespace Miles\PlatformClient\Tenancy;

use Miles\PlatformClient\Api\Organization;

/**
 * The organization the current request (or job) works for. Bound as a
 * scoped instance: reset between requests and queued jobs.
 */
final class CurrentTenant
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

    public function get(): ?Organization
    {
        return $this->organization;
    }

    public function id(): ?string
    {
        return $this->organization?->id;
    }

    /**
     * @throws MissingTenant
     */
    public function require(): Organization
    {
        return $this->organization ?? throw new MissingTenant('No tenant for this request or job.');
    }

    /**
     * Run code for a tenant (queued jobs, provisioning, console), then
     * restore what was there before.
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
