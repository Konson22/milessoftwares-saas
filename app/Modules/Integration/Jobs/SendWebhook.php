<?php

namespace App\Modules\Integration\Jobs;

use App\Modules\Integration\Models\WebhookDelivery;
use App\Modules\Integration\Support\WebhookSignature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Delivers one webhook. Retries are scheduled by the delivery row itself
 * (next_attempt_at), so a lost queue job is picked up again by
 * `platform:retry-webhooks`.
 */
class SendWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Seconds to wait after each failed attempt; the delivery fails for good
     * after the last one.
     */
    public const BACKOFF = [60, 300, 1800, 7200, 21600];

    public function __construct(public readonly string $deliveryId) {}

    public function handle(): void
    {
        $delivery = WebhookDelivery::query()->with('endpoint')->find($this->deliveryId);

        if ($delivery === null || $delivery->status !== 'pending' || $delivery->endpoint->status !== 'active') {
            return;
        }

        $body = json_encode($delivery->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = now()->getTimestamp();

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'MilesSoftwares-Webhooks/1',
                    'Miles-Event-Id' => $delivery->id,
                    'Miles-Event-Type' => $delivery->event_type,
                    WebhookSignature::HEADER => WebhookSignature::sign($delivery->endpoint->secret, $body, $timestamp),
                ])
                ->withBody($body, 'application/json')
                ->post($delivery->endpoint->url);

            $response->successful()
                ? $this->delivered($delivery, $response->status())
                : $this->failedAttempt($delivery, $response->status(), 'HTTP '.$response->status());
        } catch (ConnectionException $e) {
            $this->failedAttempt($delivery, null, $e->getMessage());
        } catch (Throwable $e) {
            $this->failedAttempt($delivery, null, $e::class.': '.$e->getMessage());
        }
    }

    private function delivered(WebhookDelivery $delivery, int $status): void
    {
        $delivery->forceFill([
            'status' => 'delivered',
            'attempts' => $delivery->attempts + 1,
            'last_response_code' => $status,
            'last_error' => null,
            'next_attempt_at' => null,
            'delivered_at' => now(),
        ])->save();
    }

    private function failedAttempt(WebhookDelivery $delivery, ?int $status, string $error): void
    {
        $attempts = $delivery->attempts + 1;
        $delay = self::BACKOFF[$attempts - 1] ?? null;

        $delivery->forceFill([
            'status' => $delay === null ? 'failed' : 'pending',
            'attempts' => $attempts,
            'last_response_code' => $status,
            'last_error' => mb_substr($error, 0, 1000),
            'next_attempt_at' => $delay === null ? null : now()->addSeconds($delay),
        ])->save();

        if ($delay !== null) {
            self::dispatch($delivery->id)->delay($delay);
        }
    }
}
