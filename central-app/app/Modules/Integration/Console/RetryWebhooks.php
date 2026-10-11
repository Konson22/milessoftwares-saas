<?php

namespace App\Modules\Integration\Console;

use App\Modules\Integration\Jobs\SendWebhook;
use App\Modules\Integration\Models\WebhookDelivery;
use Illuminate\Console\Command;

/**
 * Safety net for lost queue jobs: re-dispatches pending deliveries whose
 * next attempt is more than five minutes overdue.
 */
class RetryWebhooks extends Command
{
    protected $signature = 'platform:retry-webhooks';

    protected $description = 'Re-dispatch overdue pending webhook deliveries';

    public function handle(): int
    {
        $count = 0;

        WebhookDelivery::query()
            ->where('status', 'pending')
            ->where('next_attempt_at', '<=', now()->subMinutes(5))
            ->orderBy('next_attempt_at')
            ->limit(500)
            ->pluck('id')
            ->each(function (string $id) use (&$count): void {
                SendWebhook::dispatch($id);
                $count++;
            });

        $this->components->info("{$count} deliveries re-dispatched.");

        return self::SUCCESS;
    }
}
