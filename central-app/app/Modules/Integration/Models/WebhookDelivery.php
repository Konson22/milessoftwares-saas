<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One event for one endpoint. The id is the event_id products deduplicate on.
 *
 * @property string $id
 * @property string $webhook_endpoint_id
 * @property string|null $organization_id
 * @property string $event_type
 * @property array<string, mixed> $payload
 * @property string $status pending|delivered|failed
 * @property int $attempts
 * @property Carbon|null $next_attempt_at
 * @property int|null $last_response_code
 * @property string|null $last_error
 * @property Carbon|null $delivered_at
 * @property Carbon|null $created_at
 * @property-read WebhookEndpoint $endpoint
 */
class WebhookDelivery extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
