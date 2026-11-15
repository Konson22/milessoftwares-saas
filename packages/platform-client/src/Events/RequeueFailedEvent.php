<?php

namespace Miles\PlatformClient\Events;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * platform:outbox-requeue: the manual recovery step for ONE event the
 * receiving module refused for good (401/403/409/422, or given up after the
 * last attempt). Nothing retries such an event by itself; a person sends it
 * again only once the cause is fixed (a key published, an organization
 * switched on, a contract bug corrected on the receiving side), giving the
 * reason. The event is sent exactly as it was: same transaction id, same
 * payload (a new delivery id, as on every attempt), so the receiver still
 * applies it at most once.
 */
final class RequeueFailedEvent extends Command
{
    protected $signature = 'platform:outbox-requeue {id : the outbox event id} {--reason= : why it is safe to send again (required)} {--by= : who decided (defaults to the OS user)}';

    protected $description = 'Send one refused shared-module event again, unchanged, after its cause is fixed';

    public function handle(): int
    {
        $id = $this->argument('id');
        $reason = $this->option('reason');
        $by = $this->option('by');
        $id = is_string($id) ? $id : '';
        $reason = is_string($reason) ? trim($reason) : '';
        $by = is_string($by) && trim($by) !== '' ? trim($by) : get_current_user();

        if ($reason === '') {
            $this->components->error('Say why it is safe to send again (--reason).');

            return self::FAILURE;
        }

        $row = DB::table('platform_outbox')->where('id', $id)->first();
        if ($row === null) {
            $this->components->error("No outbox event {$id}.");

            return self::FAILURE;
        }
        if ($row->status !== 'failed') {
            $this->components->error("Event {$id} is {$row->status}: only a failed event is sent again.");

            return self::FAILURE;
        }

        DB::table('platform_outbox')->where('id', $id)->where('status', 'failed')->update([
            'status' => 'pending',
            'attempts' => 0,
            'next_attempt_at' => now(),
            'last_error' => mb_substr("Requeued by {$by}: {$reason} (was: {$row->last_error})", 0, 500),
            'updated_at' => now(),
        ]);
        Log::notice('platform.outbox.requeued', ['id' => $id, 'event_type' => $row->event_type, 'audience' => $row->audience, 'organization_id' => $row->organization_id, 'by' => $by, 'reason' => $reason, 'was' => $row->last_error]);
        DeliverOutboxEvent::dispatch($id);

        $this->components->info("Event {$id} ({$row->event_type}) queued again.");

        return self::SUCCESS;
    }
}
