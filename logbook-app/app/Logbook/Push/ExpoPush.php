<?php

namespace App\Logbook\Push;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * The Expo push service (https://docs.expo.dev/push-notifications/sending-notifications/).
 *
 * send() posts messages in batches of 100 and returns one ticket per
 * message, in order: ['status' => 'ok', 'id' => …] or ['status' =>
 * 'error', 'error' => 'DeviceNotRegistered' | …, 'message' => …].
 * receipts() asks what happened to tickets later. A transport failure or
 * a 5xx/429 answer throws PushUnavailable so the caller retries.
 */
final class ExpoPush
{
    public const BATCH = 100;

    public static function enabled(): bool
    {
        return (bool) config('logbook.push.enabled');
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return list<array{status: string, id?: string|null, error?: string|null, message?: string|null}>
     *
     * @throws PushUnavailable
     */
    public static function send(array $messages): array
    {
        $tickets = [];
        foreach (array_chunk($messages, self::BATCH) as $batch) {
            $body = self::post('/send', $batch);
            $data = is_array($body['data'] ?? null) ? array_values($body['data']) : [];
            foreach ($batch as $i => $_) {
                $ticket = is_array($data[$i] ?? null) ? $data[$i] : ['status' => 'error', 'message' => 'No ticket returned'];
                $tickets[] = [
                    'status' => (string) ($ticket['status'] ?? 'error'),
                    'id' => isset($ticket['id']) ? (string) $ticket['id'] : null,
                    'error' => isset($ticket['details']['error']) ? (string) $ticket['details']['error'] : null,
                    'message' => isset($ticket['message']) ? mb_substr((string) $ticket['message'], 0, 200) : null,
                ];
            }
        }

        return $tickets;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array{status: string, error?: string|null}> by ticket id
     *
     * @throws PushUnavailable
     */
    public static function receipts(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 300) as $batch) {
            $body = self::post('/getReceipts', ['ids' => $batch]);
            foreach ((array) ($body['data'] ?? []) as $id => $receipt) {
                $out[(string) $id] = [
                    'status' => (string) ($receipt['status'] ?? 'error'),
                    'error' => isset($receipt['details']['error']) ? (string) $receipt['details']['error'] : null,
                ];
            }
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws PushUnavailable
     */
    private static function post(string $path, array $payload): array
    {
        try {
            $response = self::client()->post(rtrim((string) config('logbook.push.endpoint'), '/').$path, $payload);
        } catch (ConnectionException $e) {
            throw new PushUnavailable('Push service unreachable: '.$e->getMessage(), previous: $e);
        }
        if ($response->serverError() || $response->status() === 429) {
            throw new PushUnavailable('Push service answered '.$response->status());
        }
        if ($response->failed()) {
            // A 4xx is our mistake (bad token format, bad credentials): retrying will not help.
            throw new PushRejected('Push service refused the request ('.$response->status().'): '.mb_substr($response->body(), 0, 200));
        }

        return (array) $response->json();
    }

    private static function client(): PendingRequest
    {
        $request = Http::acceptJson()->asJson()->timeout((int) config('logbook.push.timeout', 10));
        $token = config('logbook.push.access_token');

        return is_string($token) && $token !== '' ? $request->withToken($token) : $request;
    }
}
