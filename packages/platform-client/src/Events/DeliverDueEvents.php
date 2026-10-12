<?php

namespace Miles\PlatformClient\Events;

use Illuminate\Console\Command;

/**
 * platform:outbox: queues delivery of every outbox event whose retry is due.
 * Schedule it every minute in the product (routes/console.php).
 */
final class DeliverDueEvents extends Command
{
    protected $signature = 'platform:outbox {--limit=500 : At most this many events per run}';

    protected $description = 'Queue delivery of shared-module events that are due for a (re)try';

    public function handle(Outbox $outbox): int
    {
        $due = $outbox->due((int) $this->option('limit'));

        foreach ($due as $id) {
            DeliverOutboxEvent::dispatch($id);
        }

        $this->components->info(count($due).' event(s) queued.');

        return self::SUCCESS;
    }
}
