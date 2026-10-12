<?php

namespace Miles\PlatformClient\Events;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Miles\PlatformClient\Api\NotVisible;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Api\PlatformUnavailable;
use Miles\PlatformClient\PlatformConfig;
use Miles\PlatformClient\Tenancy\CurrentTenant;
use stdClass;

/**
 * Business events for shared modules (docs/shared-modules.md), sent
 * reliably:
 *
 * - record() writes the event in the caller's database transaction, so it
 *   exists exactly when the sale or receipt does, and queues delivery
 *   after commit.
 * - deliver() gets an event token for the organization and module and
 *   POSTs the envelope. The source_transaction_id stays the same on every
 *   attempt; the module applies it once.
 *
 * Outcomes: delivered (201), duplicate (200, already applied), skipped (the
 * organization is not entitled to the module), failed (409/401/403/422, or
 * out of attempts: needs a person), otherwise retried with backoff.
 */
final class Outbox
{
    public const BACKOFF_SECONDS = [60, 300, 1800, 7200, 21600];

    public const MAX_ATTEMPTS = 8;

    /** A delivery claims the row for this long (crashed workers release it). */
    private const LEASE_SECONDS = 120;

    public function __construct(
        private readonly PlatformConfig $config,
        private readonly PlatformApi $api,
        private readonly CurrentTenant $tenant,
        private readonly Http $http,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return string the outbox id
     */
    public function record(string $audience, string $eventType, int $schemaVersion, string $sourceTransactionId, array $payload, ?DateTimeInterface $occurredAt = null): string
    {
        $id = (string) Str::uuid7();

        DB::table('platform_outbox')->insert([
            'id' => $id,
            'organization_id' => $this->tenant->require()->id,
            'audience' => $audience,
            'event_type' => $eventType,
            'schema_version' => $schemaVersion,
            'source_transaction_id' => $sourceTransactionId,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'occurred_at' => CarbonImmutable::instance($occurredAt ?? now()),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DeliverOutboxEvent::dispatch($id)->afterCommit();

        return $id;
    }

    public function deliver(string $id): void
    {
        $now = now();

        // Claim the row: due and pending, nobody else holding it.
        $claimed = DB::table('platform_outbox')
            ->where('id', $id)
            ->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now))
            ->update([
                'attempts' => DB::raw('attempts + 1'),
                'next_attempt_at' => $now->copy()->addSeconds(self::LEASE_SECONDS),
                'updated_at' => $now,
            ]);

        if ($claimed === 0) {
            return;
        }

        $row = DB::table('platform_outbox')->where('id', $id)->first();
        if ($row === null) {
            return;
        }

        $url = $this->moduleUrl((string) $row->audience);
        if ($url === null) {
            $this->retryLater($row, "No events URL configured for module [{$row->audience}] (platform-client.modules).", final: false);

            return;
        }

        try {
            $token = $this->api->eventToken((string) $row->organization_id, (string) $row->audience);
        } catch (NotVisible) {
            $this->finish($id, 'skipped', "The organization is not entitled to [{$row->audience}].");

            return;
        } catch (PlatformUnavailable $e) {
            $this->retryLater($row, 'Event token: '.$e->getMessage());

            return;
        }

        try {
            $response = $this->http->withToken($token)->acceptJson()->timeout($this->config->httpTimeout())
                ->post($url, $this->envelope($row));
        } catch (ConnectionException $e) {
            $this->retryLater($row, 'Module unreachable: '.$e->getMessage());

            return;
        }

        match (true) {
            $response->status() === 201 => $this->finish($id, 'delivered'),
            $response->status() === 200 => $this->finish($id, 'duplicate'),
            // The module will never accept this as it is: a person must look.
            in_array($response->status(), [401, 403, 409, 422], true) => $this->finish($id, 'failed', "HTTP {$response->status()}: ".substr($response->body(), 0, 300)),
            default => $this->retryLater($row, "HTTP {$response->status()}: ".substr($response->body(), 0, 300)),
        };
    }

    /**
     * Ids of pending events whose next attempt is due.
     *
     * @return list<string>
     */
    public function due(int $limit = 100): array
    {
        /** @var list<string> */
        return DB::table('platform_outbox')
            ->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('created_at')
            ->limit($limit)
            ->pluck('id')
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function envelope(stdClass $row): array
    {
        return [
            // New per attempt; the module deduplicates on source_transaction_id.
            'event_id' => (string) Str::uuid7(),
            'event_type' => (string) $row->event_type,
            'schema_version' => (int) $row->schema_version,
            'organization_id' => (string) $row->organization_id,
            'source_app' => $this->config->application(),
            'source_transaction_id' => (string) $row->source_transaction_id,
            'occurred_at' => CarbonImmutable::parse((string) $row->occurred_at)->toIso8601String(),
            'payload' => json_decode((string) $row->payload, true, flags: JSON_THROW_ON_ERROR),
        ];
    }

    private function moduleUrl(string $audience): ?string
    {
        $url = config("platform-client.modules.{$audience}");

        return is_string($url) && $url !== '' ? $url : null;
    }

    private function finish(string $id, string $status, ?string $error = null): void
    {
        DB::table('platform_outbox')->where('id', $id)->update([
            'status' => $status,
            'next_attempt_at' => null,
            'last_error' => $error,
            'delivered_at' => in_array($status, ['delivered', 'duplicate'], true) ? now() : null,
            'updated_at' => now(),
        ]);

        if ($status === 'failed') {
            Log::error('Outbox event failed and needs attention', ['outbox_id' => $id, 'error' => $error]);
        }
    }

    private function retryLater(stdClass $row, string $error, bool $final = true): void
    {
        $attempts = (int) $row->attempts;

        if ($final && $attempts >= self::MAX_ATTEMPTS) {
            $this->finish((string) $row->id, 'failed', "Gave up after {$attempts} attempts. {$error}");

            return;
        }

        $delay = self::BACKOFF_SECONDS[min($attempts - 1, count(self::BACKOFF_SECONDS) - 1)];

        DB::table('platform_outbox')->where('id', $row->id)->update([
            'next_attempt_at' => now()->addSeconds($delay),
            'last_error' => substr($error, 0, 500),
            'updated_at' => now(),
        ]);
    }
}
