<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Miles\PlatformClient\Api\NotVisible;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * Runs one static Logbook call for an organization on the queue (the
 * tenant is not carried by a queued job otherwise). Arguments are ids and
 * scalars only. An organization Central no longer shows Logbook (left,
 * unentitled) is skipped.
 */
class RunInWorkspace implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /**
     * @param  class-string  $class
     * @param  list<scalar|null>  $args
     */
    public function __construct(public string $organizationId, public string $class, public string $method, public array $args) {}

    public function handle(PlatformApi $platform, CurrentTenant $tenant): void
    {
        try {
            $organization = $platform->organization($this->organizationId);
        } catch (NotVisible) {
            return;
        }

        $tenant->run($organization, fn () => ($this->class)::{$this->method}(...$this->args));
    }
}
