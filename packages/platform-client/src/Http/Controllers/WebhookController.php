<?php

namespace Miles\PlatformClient\Http\Controllers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\PlatformConfig;
use Miles\PlatformClient\Webhooks\PlatformEventReceived;
use Miles\PlatformClient\Webhooks\Signature;

/**
 * POST platform/webhooks. Verifies the signature over the raw body, keeps
 * each event_id once (the platform retries and may deliver twice), drops the
 * organization's cached access, then tells the product.
 */
final class WebhookController
{
    public function __construct(
        private readonly PlatformConfig $config,
        private readonly PlatformApi $api,
        private readonly Dispatcher $events,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $body = $request->getContent();

        if (! Signature::valid($request->header(Signature::HEADER), $body, $this->config->webhookSecret(), now()->getTimestamp())) {
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $event = json_decode($body, true);
        $eventId = is_array($event) ? ($event['event_id'] ?? null) : null;
        $type = is_array($event) ? ($event['event_type'] ?? null) : null;

        if (! is_string($eventId) || ! Str::isUuid($eventId) || ! is_string($type) || $type === '') {
            return response()->json(['error' => 'invalid_event'], 422);
        }

        $organizationId = is_string($event['organization_id'] ?? null) && Str::isUuid($event['organization_id']) ? $event['organization_id'] : null;

        if ($organizationId !== null) {
            $this->api->forget($organizationId);
        }

        // Recorded and handled together: if a listener fails, the record is
        // rolled back and the platform's retry is processed, not dropped.
        try {
            DB::transaction(function () use ($eventId, $type, $organizationId, $event): void {
                DB::table('platform_webhook_events')->insert([
                    'event_id' => $eventId,
                    'event_type' => $type,
                    'organization_id' => $organizationId,
                    'received_at' => now(),
                ]);

                $this->events->dispatch(new PlatformEventReceived(
                    eventId: $eventId,
                    type: $type,
                    organizationId: $organizationId,
                    data: is_array($event['data'] ?? null) ? $event['data'] : [],
                ));
            });
        } catch (UniqueConstraintViolationException) {
            return response()->json(['status' => 'duplicate', 'event_id' => $eventId]);
        }

        return response()->json(['status' => 'received', 'event_id' => $eventId]);
    }
}
