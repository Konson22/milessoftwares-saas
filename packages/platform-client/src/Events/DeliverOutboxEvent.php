<?php

namespace Miles\PlatformClient\Events;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Delivers one outbox event. Retries are the outbox's own (backoff in the
 * table), so the queue never retries this job.
 */
final class DeliverOutboxEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $outboxId) {}

    public function handle(Outbox $outbox): void
    {
        $outbox->deliver($this->outboxId);
    }
}
